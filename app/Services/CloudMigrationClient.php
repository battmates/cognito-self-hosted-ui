<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudMigrationClient
{
    public function authenticate(array $provider, string $identifier, string $password): ?array
    {
        $baseUrl = rtrim((string) ($provider['base_url'] ?? ''), '/');
        $username = $provider['username'] ?? '';
        $servicePassword = $provider['password'] ?? '';
        if (! $this->validUrl($baseUrl) || ! is_string($username) || $username === '' || ! is_string($servicePassword) || $servicePassword === '') {
            throw new RuntimeException('Cloud account verification is not configured. Please contact support.');
        }

        $token = $this->token($baseUrl, $username, $servicePassword);
        try {
            $response = Http::withToken($token)->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post($baseUrl.'/backstage/ssoauthenticate', ['identifier' => $identifier, 'password' => $password]);
        } catch (ConnectionException) {
            Log::error('portal.cloud.verification.failed', ['reason' => 'connection', 'http_status' => null]);
            throw new RuntimeException('Cloud account verification is unavailable. Please try again later.');
        }

        $data = $response->json();
        $challenge = (string) $response->header('WWW-Authenticate');
        preg_match('/\berror="?(invalid_token|invalid_request|insufficient_scope)\b/', $challenge, $error);
        $challengeError = $error[1] ?? null;
        $noMatch = is_array($data) && ($data['authenticated'] ?? null) === false
            && array_key_exists('user', $data) && $data['user'] === null;

        // Cloud includes a generic authentication challenge on account no-match
        // responses too. Only an explicit challenge error takes precedence over
        // the negative account result; never regenerate tokens for bad logins.
        if ($response->status() === 404
            || ($response->status() === 401 && $challengeError === null && ($challenge === '' || $noMatch))) {
            return null;
        }
        if ($response->status() === 200 && $noMatch) {
            return null;
        }
        if (! $response->ok()) {
            if ($response->status() === 401) {
                Log::error('portal.cloud.authorization.rejected', [
                    'authenticated_false' => is_array($data) && ($data['authenticated'] ?? null) === false,
                    'user_is_null' => is_array($data) && array_key_exists('user', $data) && $data['user'] === null,
                    'bearer_challenge' => $challenge !== '',
                    'challenge_error' => $challengeError,
                ]);
            }
            // Never log the response body, request or exception: they may contain credentials.
            Log::error('portal.cloud.verification.failed', ['reason' => 'http_error', 'http_status' => $response->status()]);
            throw new RuntimeException('Cloud account verification is unavailable. Please try again later.');
        }
        if (! is_array($data)) {
            Log::error('portal.cloud.verification.failed', ['reason' => 'invalid_response', 'http_status' => $response->status()]);
            throw new RuntimeException('Cloud account verification returned an invalid response.');
        }

        return $data;
    }

    private function token(string $baseUrl, string $username, string $password): string
    {
        // Scope cached tokens to the endpoint and credentials, so rotating the
        // service account cannot reuse the previous account's bearer token.
        $key = 'portal.cloud.token.'.hash('sha256', json_encode([$baseUrl, $username, $password], JSON_THROW_ON_ERROR));
        if ($token = $this->cachedToken($key)) {
            return $token;
        }

        try {
            return Cache::lock($key.'.lock', 30)->block(20, function () use ($key, $baseUrl, $username, $password): string {
                if ($token = $this->cachedToken($key)) {
                    return $token;
                }
                try {
                    $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                        ->get($baseUrl.'/Account/generate-token', ['userName' => $username, 'password' => $password]);
                } catch (ConnectionException) {
                    // Do not expose the GET request URL, which contains credentials.
                    throw new RuntimeException('Cloud token service is unavailable. Please try again later.');
                }
                if (! $response->ok()) {
                    throw new RuntimeException('Cloud token service is unavailable. Please try again later.');
                }
                $token = $response->json('Token');
                $expiry = $response->json('ExpireTime');
                if (! is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token) || ! is_string($expiry) || trim($expiry) === '') {
                    throw new RuntimeException('Cloud token service returned an invalid response.');
                }
                try {
                    $expiresAt = (new DateTimeImmutable($expiry, new DateTimeZone('UTC')))->getTimestamp();
                } catch (\Exception) {
                    throw new RuntimeException('Cloud token service returned an invalid expiry.');
                }
                $ttl = $expiresAt - now()->getTimestamp();
                if ($ttl <= 0) {
                    throw new RuntimeException('Cloud token service returned an expired token.');
                }
                Cache::put($key, ['token' => Crypt::encryptString($token), 'expires_at' => $expiresAt], $ttl);

                return $token;
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Cloud token service is busy. Please try again.');
        }
    }

    private function cachedToken(string $key): ?string
    {
        $cached = Cache::get($key);
        if (! is_array($cached) || ($cached['expires_at'] ?? 0) <= now()->getTimestamp() || ! is_string($cached['token'] ?? null)) {
            return null;
        }
        try {
            return Crypt::decryptString($cached['token']);
        } catch (DecryptException) {
            return null;
        }
    }

    private function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
