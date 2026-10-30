<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LegacyMigrationService
{
    public function __construct(private readonly CognitoDirectory $directory) {}

    public function resolve(string $identifier, string $password): string
    {
        if (! config('migration.enabled')) {
            return $identifier;
        }
        if (! config('sso.management_writes_enabled')) {
            throw new RuntimeException('Account migration is not available yet. Please contact support.');
        }
        if ($this->directory->hasIdentity($identifier)) {
            return $identifier;
        }
        $identity = null;
        // WordPress takes priority. Only try Cloud when WordPress has no match.
        foreach (['wordpress', 'cloud'] as $name) {
            $provider = config('migration.providers.'.$name, []);
            if ($provider['enabled'] ?? false) {
                $identity = $this->verify($name, $provider, $identifier, $password);
                if ($identity) {
                    break;
                }
            }
        }
        if (! $identity) {
            throw new RuntimeException('Incorrect email, username, or password.');
        }

        // Different username/email entry points for the same person share a lock.
        return Cache::lock('portal.migrate.'.hash('sha256', strtolower($identity['email'])), 60)->block(3, function () use ($identity, $password) {
            $username = $this->directory->provision($identity, $password);
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
            if ($this->directory->hasIdentity($identity['username'])) {
                return $identity['username'];
            }
            if ($this->directory->hasIdentity($identity['email'])) {
                return $identity['email'];
            }

            return $this->directory->provisionForPasswordRecovery($identity);
        });
    }

    private function verify(string $source, array $provider, string $identifier, string $password): ?array
    {
        $data = $source === 'cloud'
            ? app(CloudMigrationClient::class)->authenticate($provider, $identifier, $password)
            : $this->verifyWordPress($provider, $identifier, $password);
        if ($data === null) {
            return null;
        }

        return $this->verifiedIdentity($source, $data, $identifier);
    }

    private function verifyWordPress(array $provider, string $identifier, string $password): ?array
    {
        $endpoint = $provider['endpoint'] ?? '';
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! parse_url($endpoint, PHP_URL_HOST) || parse_url($endpoint, PHP_URL_USER) || parse_url($endpoint, PHP_URL_FRAGMENT) || empty($provider['token'])) {
            throw new RuntimeException('Legacy account verification is not configured. Please contact support.');
        }
        // No redirects or retries: credentials go only to the operator-configured endpoint.
        $response = Http::withToken($provider['token'])->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(15)->withoutRedirecting()
            ->post($endpoint, ['identifier' => $identifier, 'password' => $password]);
        if (in_array($response->status(), [401, 404], true)) {
            return null;
        }
        if (! $response->ok()) {
            throw new RuntimeException('Legacy account verification is unavailable. Please try again later.');
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Legacy account verification returned an invalid response.');
        }

        return $data;
    }

    private function verifiedIdentity(string $source, array $data, string $identifier): array
    {
        if (! is_array($data) || ($data['authenticated'] ?? null) !== true || ! is_array($data['user'] ?? null)) {
            throw new RuntimeException('Legacy account verification returned an invalid response.');
        }
        $user = $data['user'];
        foreach (['id', 'username', 'email', 'given_name', 'family_name'] as $key) {
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
        $username = $user['username'];
        if (! filter_var($username, FILTER_VALIDATE_EMAIL) && (strlen($username) > 128 || ! preg_match('/^[\p{L}\p{M}\p{S}\p{N}\p{P}]+$/uD', $username))) {
            throw new RuntimeException('The legacy username needs review before migration.');
        }

        // Explicit whitelist: never import roles, verification overrides or admin flags.
        return ['source' => $source, 'id' => $user['id'], 'username' => $username, 'email' => strtolower($user['email']), 'given_name' => $user['given_name'], 'family_name' => $user['family_name']];
    }

    private function lookupWordPress(array $provider, string $identifier): ?array
    {
        $endpoint = $provider['lookup_endpoint'] ?? '';
        $secret = $provider['token'] ?? '';
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
        foreach (['id', 'user_login', 'email', 'first_name', 'last_name'] as $key) {
            if ((! is_string($user[$key] ?? null) && ! is_int($user[$key] ?? null)) || trim((string) $user[$key]) === '' || strlen((string) $user[$key]) > 256) {
                throw new RuntimeException('The legacy account is missing required identity details. Please contact support.');
            }
        }
        if (! filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The legacy account has an invalid email address. Please contact support.');
        }

        return [
            'source' => 'wordpress', 'id' => (string) $user['id'], 'username' => (string) $user['user_login'],
            'email' => strtolower((string) $user['email']), 'given_name' => (string) $user['first_name'], 'family_name' => (string) $user['last_name'],
        ];
    }

    private function validEndpoint(string $endpoint): bool
    {
        return parse_url($endpoint, PHP_URL_SCHEME) === 'https'
            && (bool) parse_url($endpoint, PHP_URL_HOST)
            && ! parse_url($endpoint, PHP_URL_USER)
            && ! parse_url($endpoint, PHP_URL_FRAGMENT);
    }
}
