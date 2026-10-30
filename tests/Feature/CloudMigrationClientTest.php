<?php

namespace Tests\Feature;

use App\Services\CloudMigrationClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CloudMigrationClientTest extends TestCase
{
    private function provider(): array
    {
        return ['base_url' => 'https://cloud.example.test/', 'username' => 'service-user', 'password' => 'service-password'];
    }

    private function fakeCloud(array $tokenOverrides = []): void
    {
        Http::fake([
            'https://cloud.example.test/generate-token*' => fn () => Http::response(array_merge([
                'Token' => 'cached-bearer', 'URL' => 'https://untrusted.example.test/',
                'CreatedTime' => now()->toIso8601String(), 'ExpireTime' => now()->addHour()->toIso8601String(),
            ], $tokenOverrides)),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['authenticated' => false, 'user' => null]),
        ]);
    }

    public function test_token_is_reused_across_users_and_client_instances_until_expiry(): void
    {
        $this->freezeTime();
        $this->fakeCloud();
        $this->assertNull((new CloudMigrationClient)->authenticate($this->provider(), 'first@example.com', 'first-password'));
        $this->travel(59)->minutes();
        $this->assertNull((new CloudMigrationClient)->authenticate($this->provider(), 'second@example.com', 'second-password'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['userName'] === 'service-user' && $request['password'] === 'service-password');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['identifier'] === 'second@example.com' && $request['password'] === 'second-password' && $request->hasHeader('Authorization', 'Bearer cached-bearer'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'untrusted.example.test'));

        $this->travel(1)->minutes();
        (new CloudMigrationClient)->authenticate($this->provider(), 'third@example.com', 'third-password');
        Http::assertSentCount(5);
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET'));
    }

    public function test_rotating_service_credentials_does_not_reuse_old_token(): void
    {
        $this->fakeCloud();
        $client = new CloudMigrationClient;
        $client->authenticate($this->provider(), 'first@example.com', 'password');
        $client->authenticate(array_merge($this->provider(), ['password' => 'rotated-secret']), 'first@example.com', 'password');
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['password'] === 'rotated-secret');
    }

    public function test_expired_token_is_never_used_for_authentication(): void
    {
        $this->fakeCloud(['ExpireTime' => now()->subSecond()->toIso8601String()]);
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
            $this->fail('An expired token must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloud token service returned an expired token.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_malformed_token_is_not_cached_or_used(): void
    {
        $this->fakeCloud(['Token' => '']);
        $this->expectExceptionMessage('Cloud token service returned an invalid response.');
        (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
    }

    public function test_missing_expiry_is_rejected_before_sending_user_credentials(): void
    {
        $this->fakeCloud(['ExpireTime' => null]);
        $this->expectExceptionMessage('Cloud token service returned an invalid response.');
        (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
    }

    public function test_token_connection_failure_does_not_expose_service_credentials(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://cloud.example.test/generate-token?userName=service-user&password=service-password'));
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
            $this->fail('A connection failure must stop authentication.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloud token service is unavailable. Please try again later.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_authorization_failure_does_not_request_token_again_before_expiry(): void
    {
        Http::fake([
            'https://cloud.example.test/generate-token*' => Http::response(['Token' => 'cached-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response([], 401),
        ]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
                $this->fail('A service authorization failure must stop migration.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Cloud account verification is unavailable. Please try again later.', $exception->getMessage());
            }
        }
        Http::assertSentCount(3);
    }

    public function test_token_generation_rechecks_the_cache_after_taking_the_lock(): void
    {
        $provider = $this->provider();
        $key = 'portal.cloud.token.'.hash('sha256', json_encode(['https://cloud.example.test', $provider['username'], $provider['password']], JSON_THROW_ON_ERROR));
        Cache::shouldReceive('get')->with($key)->once()->andReturn(null);
        Cache::shouldReceive('lock')->with($key.'.lock', 30)->once()->andReturn(new class
        {
            public function block(int $seconds, callable $callback): string
            {
                return $callback();
            }
        });
        Cache::shouldReceive('get')->with($key)->once()->andReturn([
            'token' => Crypt::encryptString('shared-bearer'), 'expires_at' => now()->addHour()->getTimestamp(),
        ]);
        Http::fake(['https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['authenticated' => false, 'user' => null])]);

        (new CloudMigrationClient)->authenticate($provider, 'person@example.com', 'password');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-bearer'));
    }
}
