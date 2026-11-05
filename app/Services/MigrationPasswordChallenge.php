<?php

namespace App\Services;

use App\Exceptions\MigrationPasswordRequiredException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MigrationPasswordChallenge
{
    public function __construct(private readonly CognitoDirectory $directory, private readonly LegacyMigrationService $migration) {}

    public function start(string $sessionId, array $identity, array $context, ?array $authorization): array
    {
        if (! in_array($identity['source'] ?? null, ['cloud', 'wordpress'], true) || ! filter_var($identity['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The verified account details are missing. Please sign in again.');
        }
        $id = (string) Str::uuid();
        $pending = [
            'identity' => Arr::only($identity, ['source', 'id', 'username', 'email', 'given_name', 'family_name', 'preferred_username']),
            'context' => $context, 'authorization' => $authorization,
            'requirements' => $this->directory->migrationPasswordPolicy(), 'expires_at' => now()->getTimestamp() + 300,
        ];
        $this->save($sessionId, $id, $pending);

        return $this->presentation($id, $pending);
    }

    public function show(string $sessionId, string $id): array
    {
        return $this->presentation($id, $this->pending($sessionId, $id));
    }

    public function complete(string $sessionId, string $id, string $password): array
    {
        $key = $this->key($sessionId, $id);
        $lock = Cache::lock($key.'.lock', 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['password' => 'Your account is already being set up. Please wait for it to finish.']);
        }
        try {
            $pending = $this->pending($sessionId, $id);
            $rules = ['required', 'string', 'max:256', 'min:'.$pending['requirements']['minimum_length']];
            foreach (['uppercase' => '/[A-Z]/', 'lowercase' => '/[a-z]/', 'numbers' => '/[0-9]/', 'symbols' => '/[^a-zA-Z0-9\s]/u'] as $requirement => $pattern) {
                if ($pending['requirements'][$requirement]) {
                    $rules[] = 'regex:'.$pattern;
                }
            }
            Validator::make(['password' => $password], ['password' => $rules], [
                'password.regex' => 'Your new password must meet all the requirements shown below.',
            ])->validate();

            // Consume proof before any account writes. Only a definite pre-creation
            // password-policy rejection may restore it for another attempt.
            Cache::forget($key);
            try {
                $username = $this->migration->provisionVerifiedIdentity($pending['identity'], $password);
            } catch (MigrationPasswordRequiredException) {
                $pending['requirements'] = $this->directory->migrationPasswordPolicy();
                $this->save($sessionId, $id, $pending);
                throw ValidationException::withMessages(['password' => 'Single sign-on requires a stronger password. Please choose a different password and try again.']);
            }

            return ['username' => $username, 'context' => $pending['context'], 'authorization' => $pending['authorization']];
        } finally {
            $lock->release();
        }
    }

    private function pending(string $sessionId, string $id): array
    {
        $encoded = Cache::get($this->key($sessionId, $id));
        try {
            $pending = is_string($encoded) ? json_decode(Crypt::decryptString($encoded), true) : null;
        } catch (DecryptException) {
            $pending = null;
        }
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->getTimestamp()
            || (is_array($pending['authorization'] ?? null) && ($pending['authorization']['expires_at'] ?? 0) <= now()->getTimestamp())) {
            throw ValidationException::withMessages(['challenge' => 'Your password setup has expired or was already used. Please sign in again.']);
        }

        return $pending;
    }

    private function save(string $sessionId, string $id, array $pending): void
    {
        $ttl = $pending['expires_at'] - now()->getTimestamp();
        if ($ttl > 0) {
            Cache::put($this->key($sessionId, $id), Crypt::encryptString(json_encode($pending, JSON_THROW_ON_ERROR)), $ttl);
        }
    }

    private function presentation(string $id, array $pending): array
    {
        return ['password_required' => true, 'challenge_id' => $id, 'requirements' => $pending['requirements'],
            'provider_name' => $pending['identity']['source'] === 'cloud' ? 'RSL Cloud' : 'MusicTeacher', 'expires_at' => $pending['expires_at']];
    }

    private function key(string $sessionId, string $id): string
    {
        return 'portal.migration.password.'.hash('sha256', $sessionId.':'.$id);
    }
}
