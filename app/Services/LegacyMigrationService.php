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
        if ($username = $this->directory->resolveUsername($identifier)) {
            return $username;
        }
        if (! config('sso.management_writes_enabled')) {
            throw new RuntimeException('Account migration is not available yet. Please contact support.');
        }
        foreach (config('migration.providers', []) as $name => $provider) {
            if ($provider['enabled'] ?? false) {
                $identity = $this->verify($name, $provider, $identifier, $password);
                if ($identity) {
                    foreach ([$identity['username'], $identity['email']] as $poolIdentifier) {
                        if ($username = $this->directory->resolveUsername($poolIdentifier)) {
                            return $username;
                        }
                    }

                    return $this->provision($identity, $password);
                }
            }
        }

        throw new RuntimeException('Incorrect email, username, or password.');
    }

    private function provision(array $identity, string $password): string
    {
        // Different username/email entry points for the same person share a lock.
        return Cache::lock('portal.migrate.'.hash('sha256', strtolower($identity['email'])), 60)->block(3, function () use ($identity, $password) {
            $username = $this->directory->provision($identity, $password);
            Log::notice('portal.migration', ['source' => $identity['source'], 'identity_hash' => hash('sha256', $identity['source'].':'.$identity['id']), 'outcome' => 'succeeded']);

            return $username;
        });
    }

    private function verify(string $source, array $provider, string $identifier, string $password): ?array
    {
        $endpoint = $provider['endpoint'] ?? '';
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! parse_url($endpoint, PHP_URL_HOST) || parse_url($endpoint, PHP_URL_USER) || parse_url($endpoint, PHP_URL_FRAGMENT)) {
            throw new RuntimeException('Legacy account verification is not configured. Please contact support.');
        }
        $response = match ($provider['driver'] ?? '') {
            'wordpress_hmac' => $this->verifyWordPress($provider, $endpoint, $identifier, $password),
            'json_bearer' => $this->verifyJsonBearer($provider, $endpoint, $identifier, $password),
            default => throw new RuntimeException('Legacy account verification is not configured. Please contact support.'),
        };
        if (in_array($response->status(), [401, 404], true)) {
            return null;
        }
        if (! $response->ok()) {
            throw new RuntimeException('Legacy account verification is unavailable. Please try again later.');
        }
        $data = $response->json();
        if (($provider['driver'] ?? '') === 'wordpress_hmac') {
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
        $username = $user['username'];
        $preferredUsername = null;
        if (($provider['driver'] ?? '') === 'wordpress_hmac') {
            if (! ctype_digit($user['id'])) {
                throw new RuntimeException('The legacy account is missing required identity details. Please contact support.');
            }
            $preferredUsername = $username;
            $username = 'wp_'.$user['id'];
        }
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $username = $source.'_'.substr(hash('sha256', $source.':'.$user['id']), 0, 40);
        }
        if (strlen($username) > 128 || ! preg_match('/^[\p{L}\p{M}\p{S}\p{N}\p{P}]+$/uD', $username)) {
            throw new RuntimeException('The legacy username needs review before migration.');
        }

        // Explicit whitelist: never import roles, verification overrides or admin flags.
        return ['source' => $source, 'id' => $user['id'], 'username' => $username, 'email' => strtolower($user['email']),
            'preferred_username' => $preferredUsername, 'given_name' => trim((string) ($user['given_name'] ?? '')) ?: 'Unknown',
            'family_name' => trim((string) ($user['family_name'] ?? '')) ?: 'User'];
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
}
