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
        $candidates = [];
        foreach (config('migration.providers', []) as $name => $provider) {
            if ($provider['enabled'] ?? false) {
                $identity = $this->verify($name, $provider, $identifier, $password);
                if ($identity) {
                    $candidates[] = $identity;
                }
            }
        }
        if (count($candidates) > 1) {
            throw new RuntimeException('More than one legacy account matched. Please contact support to link your accounts.');
        }
        if (count($candidates) === 0) {
            throw new RuntimeException('Incorrect email, username, or password.');
        }
        $identity = $candidates[0];

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
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $username = $source.'_'.substr(hash('sha256', $source.':'.$user['id']), 0, 40);
        }
        if (strlen($username) > 128 || ! preg_match('/^[\p{L}\p{M}\p{S}\p{N}\p{P}]+$/uD', $username)) {
            throw new RuntimeException('The legacy username needs review before migration.');
        }

        // Explicit whitelist: never import roles, verification overrides or admin flags.
        return ['source' => $source, 'id' => $user['id'], 'username' => $username, 'email' => strtolower($user['email']), 'given_name' => $user['given_name'], 'family_name' => $user['family_name']];
    }
}
