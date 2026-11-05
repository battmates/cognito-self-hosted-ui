<?php

namespace App\Services;

use App\Exceptions\MigrationPasswordRequiredException;
use App\Exceptions\ProviderCredentialsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LegacyMigrationService
{
    public function __construct(private readonly CognitoDirectory $directory) {}

    public function resolve(string $identifier, string $password, array $context = []): string
    {
        if (! config('migration.enabled')) {
            return $identifier;
        }
        if ($username = $this->directory->resolveUsername($identifier)) {
            return $username;
        }
        if (! config('sso.management_writes_enabled')) {
            throw new RuntimeException('Account migration is not available yet. Please contact support.');
        }
        // The consumer comes from the broker's validated application journey.
        $order = ($context['consumer'] ?? null) === 'musicteacher' ? ['wordpress', 'cloud'] : ['cloud', 'wordpress'];
        $providers = array_values(array_filter($order, fn ($name) => config('migration.providers.'.$name.'.enabled', false)));
        $unavailable = [];
        foreach ($providers as $position => $name) {
            $provider = config('migration.providers.'.$name, []);
            $label = $name === 'wordpress' ? 'MusicTeacher' : 'Cloud';
            app(LoginProgress::class)->report(
                $name,
                ($position === 0 ? 'No matching single sign-on account. ' : '').'Checking '.$label.' for a matching sign-in.',
                $position === 0 ? 35 : 60,
            );
            try {
                $identity = $this->verify($name, $provider, $identifier, $password);
            } catch (ProviderCredentialsException $exception) {
                app(LoginProgress::class)->report(
                    $name.'-credentials-rejected',
                    $exception->getMessage(),
                    $position === 0 ? 45 : 70,
                    'rejected',
                );

                throw $exception;
            } catch (RuntimeException) {
                // Verification failures do not prevent another provider from
                // verifying the user. Directory checks and provisioning remain
                // outside this catch so a partial creation is never retried.
                $unavailable[] = $label;
                Log::error('portal.migration.provider.skipped', ['source' => $name, 'reason' => 'verification_failed']);
                app(LoginProgress::class)->report(
                    $name.'-unavailable',
                    $label.' is unavailable right now. Skipping this service.',
                    $position === 0 ? 45 : 70,
                    'unavailable',
                );

                continue;
            }
            if (! $identity) {
                app(LoginProgress::class)->report(
                    $name.'-no-match',
                    $name === 'wordpress' ? 'No matching MusicTeacher sign-in found.' : 'No matching Cloud sign-in found.',
                    $position === 0 ? 45 : 70,
                    'no-match',
                );

                continue;
            }
            foreach ($this->identityIdentifiers($identity) as $poolIdentifier) {
                if ($username = $this->directory->resolveUsername($poolIdentifier)) {
                    return $username;
                }
            }

            return $this->provisionVerifiedIdentity($identity, $password);
        }

        if ($unavailable !== []) {
            $services = implode(' and ', $unavailable);
            $verb = count($unavailable) === 1 ? 'is' : 'are';
            $message = count($unavailable) === count($providers)
                ? 'We couldn’t check your account.'
                : 'We couldn’t find a matching account in the services we could check.';
            throw new RuntimeException($message.' '.$services.' '.$verb.' temporarily unavailable. Please try again in a little while.');
        }

        throw new RuntimeException('We couldn’t find an account matching those details. Please check your email and password.');
    }

    public function provisionVerifiedIdentity(array $identity, string $password): string
    {
        // Different username/email entry points for the same person share a lock.
        return Cache::lock('portal.migrate.'.hash('sha256', strtolower($identity['email'])), 60)->block(3, function () use ($identity, $password) {
            app(LoginProgress::class)->report('migration', 'Account verified. Setting up your single sign-on access.', 80);
            try {
                $username = $this->directory->provision($identity, $password);
            } catch (MigrationPasswordRequiredException) {
                throw new MigrationPasswordRequiredException($identity);
            }
            Log::notice('portal.migration', ['source' => $identity['source'], 'identity_hash' => hash('sha256', $identity['source'].':'.$identity['id']), 'outcome' => 'succeeded']);

            return $username;
        });
    }

    /**
     * Look up a WordPress account only after Cognito has reported it missing.
     * The reset code is still issued and verified exclusively by Cognito.
     */
    public function recoverForPasswordReset(string $identifier): ?string
    {
        if (! config('migration.password_recovery_enabled')) {
            return null;
        }

        $provider = config('migration.providers.wordpress', []);
        if (! ($provider['enabled'] ?? false)) {
            return null;
        }

        $identity = $this->lookupWordPress($provider, $identifier);
        if (! $identity) {
            return null;
        }

        return Cache::lock('portal.recover.'.hash('sha256', strtolower($identity['email'])), 60)->block(3, function () use ($identity) {
            // A concurrent sign-up or migration always wins; do not overwrite it.
            foreach ($this->identityIdentifiers($identity) as $poolIdentifier) {
                if ($username = $this->directory->resolveUsername($poolIdentifier)) {
                    return $username;
                }
            }

            return $this->directory->provisionForPasswordRecovery($identity);
        });
    }

    private function verify(string $source, array $provider, string $identifier, string $password): ?array
    {
        $data = $source === 'cloud'
            ? app(CloudMigrationClient::class)->authenticate($provider, $identifier, $password)
            : $this->verifyProvider($provider, $identifier, $password);
        if ($data === null) {
            return null;
        }

        return $this->verifiedIdentity($source, $data, $identifier, ($provider['driver'] ?? '') === 'wordpress_hmac');
    }

    private function verifyProvider(array $provider, string $identifier, string $password): ?array
    {
        $endpoint = $provider['endpoint'] ?? '';
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! parse_url($endpoint, PHP_URL_HOST) || parse_url($endpoint, PHP_URL_USER) || parse_url($endpoint, PHP_URL_FRAGMENT)) {
            throw new RuntimeException('Legacy account verification is not configured. Please contact support.');
        }
        try {
            $response = match ($provider['driver'] ?? '') {
                'wordpress_hmac' => $this->verifyWordPress($provider, $endpoint, $identifier, $password),
                'json_bearer' => $this->verifyJsonBearer($provider, $endpoint, $identifier, $password),
                default => throw new RuntimeException('Legacy account verification is not configured. Please contact support.'),
            };
        } catch (ConnectionException) {
            Log::error('portal.legacy.verification.failed', ['source' => 'wordpress', 'reason' => 'connection', 'http_status' => null]);
            throw new RuntimeException('Legacy account verification is unavailable. Please try again later.');
        }
        $data = $response->json();
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        $serviceError = ($provider['driver'] ?? '') === 'wordpress_hmac' && is_string($code)
            && (str_starts_with($code, 'cognito_migrate_') || $code === 'rest_no_route');
        if (in_array($response->status(), [401, 404], true) && ! $serviceError) {
            return null;
        }
        if (! $response->ok() || $serviceError) {
            $body = strtolower($response->body());
            if (in_array($response->status(), [403, 429, 503], true)
                && str_contains($body, 'wordfence') && str_contains($body, 'temporarily locked out')) {
                Log::error('portal.legacy.verification.failed', ['source' => 'wordpress', 'reason' => 'security_lockout', 'http_status' => $response->status()]);
                throw new RuntimeException('MusicTeacher has temporarily blocked sign-in attempts. Please wait before trying again, or contact support.');
            }
            Log::error('portal.legacy.verification.failed', ['source' => 'wordpress', 'reason' => 'http_error', 'http_status' => $response->status()]);
            throw new RuntimeException('Legacy account verification is unavailable. Please try again later.');
        }
        if (! is_array($data)) {
            Log::error('portal.legacy.verification.failed', ['source' => 'wordpress', 'reason' => 'invalid_response', 'http_status' => $response->status()]);
            throw new RuntimeException('Legacy account verification returned an invalid response.');
        }
        // Some WordPress installations return an explicit no-match with HTTP 200.
        if (($provider['driver'] ?? '') === 'wordpress_hmac' && ($data['ok'] ?? null) === false && ($data['user'] ?? null) === null) {
            return null;
        }

        return $data;
    }

    private function verifiedIdentity(string $source, array $data, string $identifier, bool $wordpress): array
    {
        if ($wordpress) {
            $data = $this->normalizeWordPressResponse($data);
        }
        if (! is_array($data) || ($data['authenticated'] ?? null) !== true || ! is_array($data['user'] ?? null)) {
            throw new RuntimeException('Legacy account verification returned an invalid response.');
        }
        $user = $data['user'];
        foreach (['id', 'username', 'email'] as $key) {
            if (! is_string($user[$key] ?? null) || trim($user[$key]) === '' || strlen($user[$key]) > 256) {
                throw new RuntimeException('The legacy account is missing required identity details. Please contact support.');
            }
        }
        if (($user['email_verified'] ?? null) !== true || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please verify your email on the original platform before migrating.');
        }
        if (! in_array(strtolower($identifier), [strtolower($user['username']), strtolower($user['email'])], true)) {
            throw new RuntimeException('The legacy response does not match the requested account.');
        }
        foreach (['given_name', 'family_name'] as $name) {
            if (! $wordpress && (! is_string($user[$name] ?? null) || trim($user[$name]) === '' || strlen($user[$name]) > 256)) {
                throw new RuntimeException('The legacy account is missing required identity details. Please contact support.');
            }
        }
        $username = $user['username'];
        $preferredUsername = null;
        if ($wordpress) {
            if (! ctype_digit($user['id'])) {
                throw new RuntimeException('The legacy account is missing required identity details. Please contact support.');
            }
            $preferredUsername = $username;
        }
        if (! filter_var($username, FILTER_VALIDATE_EMAIL) && (strlen($username) > 128 || ! preg_match('/^[\p{L}\p{M}\p{S}\p{N}\p{P}]+$/uD', $username))) {
            throw new RuntimeException('The legacy username needs review before migration.');
        }

        // Explicit whitelist: never import roles or admin flags. Legacy usernames
        // that are emails are converted to unique names at provisioning time.
        $identity = ['source' => $source, 'id' => $user['id'], 'username' => $username, 'email' => strtolower($user['email']),
            'given_name' => trim((string) ($user['given_name'] ?? '')) ?: 'Unknown',
            'family_name' => trim((string) ($user['family_name'] ?? '')) ?: 'User'];
        if ($preferredUsername !== null) {
            $identity['preferred_username'] = $preferredUsername;
        }

        return $identity;
    }

    private function verifyWordPress(array $provider, string $endpoint, string $identifier, string $password)
    {
        $secret = $provider['secret'] ?? '';
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Legacy account verification is not configured. Please contact support.');
        }
        $body = json_encode(['username' => $identifier, 'password' => $password], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        // The signature covers the exact bytes sent. Redirects and retries stay disabled
        // so credentials can only reach the configured MusicTeacher endpoint once.
        return Http::acceptJson()->withHeaders([
            'X-Cognito-Migrate-Timestamp' => $timestamp,
            'X-Cognito-Migrate-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ])->withBody($body, 'application/json')->connectTimeout(5)->timeout(15)->withoutRedirecting()->post($endpoint);
    }

    private function verifyJsonBearer(array $provider, string $endpoint, string $identifier, string $password)
    {
        if (empty($provider['token'])) {
            throw new RuntimeException('Legacy account verification is not configured. Please contact support.');
        }

        return Http::withToken($provider['token'])->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(15)->withoutRedirecting()
            ->post($endpoint, ['identifier' => $identifier, 'password' => $password]);
    }

    private function normalizeWordPressResponse(mixed $data): mixed
    {
        if (! is_array($data) || ($data['ok'] ?? null) !== true || ! is_array($data['user'] ?? null)) {
            return $data;
        }
        $user = $data['user'];

        return ['authenticated' => true, 'user' => [
            'id' => (string) ($user['id'] ?? ''),
            'username' => (string) ($user['user_login'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            // MusicTeacher historically treats the email stored on a password-verified
            // WordPress account as authoritative, as does its existing Cognito sync.
            'email_verified' => true,
            'given_name' => (string) ($user['first_name'] ?? ''),
            'family_name' => (string) ($user['last_name'] ?? ''),
        ]];
    }

    private function lookupWordPress(array $provider, string $identifier): ?array
    {
        $endpoint = $provider['lookup_endpoint'] ?? '';
        $secret = $provider['secret'] ?? $provider['token'] ?? '';
        if (! is_string($endpoint) || ! is_string($secret) || $secret === '' || ! $this->validEndpoint($endpoint)) {
            throw new RuntimeException('Legacy password recovery is not configured. Please contact support.');
        }

        $body = json_encode(['username' => $identifier], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $response = Http::acceptJson()->withBody($body, 'application/json')->withoutRedirecting()
            ->connectTimeout(5)->timeout(15)->withHeaders([
                'X-Cognito-Migrate-Timestamp' => $timestamp,
                'X-Cognito-Migrate-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            ])->post($endpoint);
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->ok()) {
            throw new RuntimeException('Legacy password recovery is unavailable. Please try again later.');
        }
        $data = $response->json();
        $user = is_array($data) && ($data['ok'] ?? null) === true && is_array($data['user'] ?? null) ? $data['user'] : null;
        if (! $user) {
            throw new RuntimeException('Legacy password recovery returned an invalid response.');
        }

        return $this->verifiedIdentity('wordpress', $data, $identifier, true);
    }

    private function identityIdentifiers(array $identity): array
    {
        $identifiers = [$identity['username'], $identity['email']];
        if ($identity['source'] === 'wordpress') {
            // Reuse accounts created by the previous WordPress migration scheme.
            array_unshift($identifiers, 'wp_'.$identity['id']);
        }

        return array_values(array_unique($identifiers));
    }

    private function validEndpoint(string $endpoint): bool
    {
        return parse_url($endpoint, PHP_URL_SCHEME) === 'https'
            && (bool) parse_url($endpoint, PHP_URL_HOST)
            && ! parse_url($endpoint, PHP_URL_USER)
            && ! parse_url($endpoint, PHP_URL_FRAGMENT);
    }
}
