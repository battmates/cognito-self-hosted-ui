<?php

namespace Tests\Feature;

use App\Exceptions\ProviderCredentialsException;
use App\Services\CloudMigrationClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'https://cloud.example.test/Account/generate-token*' => fn () => Http::response(array_merge([
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
        Http::assertSent(fn ($request) => $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/Account/generate-token' && $request['userName'] === 'service-user' && $request['password'] === 'service-password');
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

    public function test_empty_token_response_records_only_safe_diagnostic_flags(): void
    {
        Log::spy();
        $this->fakeCloud(['Token' => null, 'ExpireTime' => null]);
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'private-password');
            $this->fail('An empty token response must stop verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloud token service returned an invalid response.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Log::shouldHaveReceived('error')->with('portal.cloud.token.failed', [
            'reason' => 'invalid_response', 'http_status' => 200,
            'token_present' => false, 'token_contains_line_break' => false, 'expiry_present' => false,
        ])->once();
        Http::assertSentCount(1);
    }

    public function test_null_token_and_timestamps_are_logged_as_rejected_service_credentials(): void
    {
        Log::spy();
        $this->fakeCloud(['Token' => null, 'CreatedTime' => null, 'ExpireTime' => null]);
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'private-password');
            $this->fail('Rejected service credentials must stop Cloud verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloud service credentials were rejected.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Log::shouldHaveReceived('error')->with('portal.cloud.token.failed', [
            'reason' => 'invalid_service_credentials', 'http_status' => 200,
        ])->once();
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_token_connection_failure_does_not_expose_service_credentials(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://cloud.example.test/Account/generate-token?userName=service-user&password=service-password'));
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
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'cached-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']),
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

    #[DataProvider('rejectedCloudCredentials')]
    public function test_explicit_401_credential_rejection_stops_sign_in(array $body, array $headers): void
    {
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'cached-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response($body, 401, $headers),
        ]);
        $this->expectException(ProviderCredentialsException::class);
        $this->expectExceptionMessage('Incorrect credentials.');

        (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
    }

    public static function rejectedCloudCredentials(): array
    {
        return [
            'negative account response' => [['authenticated' => false, 'user' => null], []],
            'negative flag without user field' => [['authenticated' => false], []],
            'negative flag with generic Bearer header' => [['authenticated' => false], ['WWW-Authenticate' => 'Bearer']],
            'explicit account rejection with Bearer error' => [['authenticated' => false, 'user' => null], ['WWW-Authenticate' => 'Bearer error="invalid_token"']],
        ];
    }

    public function test_authenticated_true_preserves_the_verified_cloud_identity(): void
    {
        $identity = ['authenticated' => true, 'user' => [
            'id' => 'cloud-123', 'username' => 'person@example.com', 'email' => 'person@example.com',
            'email_verified' => true, 'given_name' => 'Aaron', 'family_name' => "O'Sullivan",
        ]];
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'cached-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response($identity),
        ]);

        $this->assertSame($identity, (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password'));
        Http::assertSentCount(2);
    }

    public function test_plain_401_without_a_service_challenge_is_a_no_match(): void
    {
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'cached-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['message' => 'Invalid credentials.'], 401),
        ]);

        $this->assertNull((new CloudMigrationClient)->authenticate($this->provider(), 'missing@example.com', 'password'));
        Http::assertSentCount(2);
    }

    public function test_bearer_challenge_is_not_treated_as_an_account_no_match(): void
    {
        Log::spy();
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'cached-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']),
        ]);
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'password');
            $this->fail('A rejected service token must stop verification.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cloud account verification is unavailable', $exception->getMessage());
        }
        Log::shouldHaveReceived('error')->with('portal.cloud.authorization.rejected', [
            'authenticated_false' => false, 'user_is_null' => false, 'bearer_challenge' => true, 'challenge_error' => 'invalid_token',
        ])->once();
        Http::assertSentCount(2);
    }

    #[DataProvider('verificationFailureStatuses')]
    public function test_verification_failure_logs_status_without_credentials_or_response_body(int $status): void
    {
        Log::spy();
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'private-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['message' => 'Private response with person@example.com and user-password'], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer error="invalid_token"'] : []),
        ]);
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'user-password');
            $this->fail('A Cloud HTTP failure must stop verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cloud account verification is unavailable. Please try again later.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Log::shouldHaveReceived('error')->with('portal.cloud.verification.failed', ['reason' => 'http_error', 'http_status' => $status])->once();
        Http::assertSentCount(2);
    }

    public static function verificationFailureStatuses(): array
    {
        return [[400], [401], [403], [429], [500], [503]];
    }

    public function test_verification_connection_failure_is_logged_without_exception_details(): void
    {
        Log::spy();
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'private-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => fn () => throw new ConnectionException('Sensitive request: person@example.com user-password private-bearer'),
        ]);
        try {
            (new CloudMigrationClient)->authenticate($this->provider(), 'person@example.com', 'user-password');
            $this->fail('A connection failure must stop verification.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
        }
        Log::shouldHaveReceived('error')->once()->with('portal.cloud.verification.failed', ['reason' => 'connection', 'http_status' => null]);
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
