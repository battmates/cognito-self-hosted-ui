<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use App\Services\LegacyMigrationService;
use App\Services\LoginProgress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProviderFallbackTest extends TestCase
{
    private function enable(): CognitoDirectory
    {
        config(['migration.enabled' => true, 'sso.management_writes_enabled' => true,
            'migration.providers' => [
                'cloud' => ['enabled' => true, 'base_url' => 'https://cloud.example.test', 'username' => 'service-user', 'password' => 'private-service-password'],
                'wordpress' => ['enabled' => true, 'driver' => 'wordpress_hmac', 'endpoint' => 'https://wordpress.example.test/verify', 'secret' => 'private-shared-secret'],
            ],
        ]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);

        return $directory;
    }

    private function cloudUser(): array
    {
        return ['authenticated' => true, 'user' => ['id' => 'cloud-123', 'username' => 'person@example.com', 'email' => 'person@example.com', 'email_verified' => true, 'given_name' => 'Example', 'family_name' => 'User']];
    }

    private function wordpressUser(): array
    {
        return ['ok' => true, 'user' => ['id' => 123, 'user_login' => 'person@example.com', 'email' => 'person@example.com', 'first_name' => 'Example', 'last_name' => 'User']];
    }

    #[DataProvider('providerFailures')]
    public function test_a_failed_provider_is_skipped_and_the_next_can_verify_the_account(string $failure, string $first): void
    {
        $directory = $this->enable();
        $expected = $first === 'cloud' ? 'wordpress' : 'cloud';
        $directory->shouldReceive('provision')->once()->with(Mockery::on(fn ($identity) => $identity['source'] === $expected), 'private-password')->andReturn('migrated-user');
        Http::fake(function ($request) use ($failure) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/Account/generate-token') {
                if ($failure === 'cloud-invalid-service-credentials') {
                    return Http::response(['Token' => null, 'URL' => 'https://cloud.example.test/', 'CreatedTime' => null, 'ExpireTime' => null]);
                }

                return Http::response(['Token' => $failure === 'cloud-token' ? null : 'private-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]);
            }
            if ($path === '/backstage/ssoauthenticate') {
                return match ($failure) {
                    'cloud-http' => Http::response([], 503),
                    'cloud-connection' => throw new ConnectionException('Private exception details'),
                    'cloud-authorization' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']),
                    'cloud-invalid-identity' => Http::response(['authenticated' => true, 'user' => ['email' => 'person@example.com']]),
                    default => Http::response($this->cloudUser()),
                };
            }

            return match ($failure) {
                'wordpress-http' => Http::response([], 503),
                'wordpress-lockout' => Http::response(['message' => 'Wordfence: temporarily locked out'], 503),
                'wordpress-connection' => throw new ConnectionException('Private exception details'),
                'wordpress-malformed' => Http::response('Not JSON'),
                'wordpress-signature' => Http::response(['code' => 'cognito_migrate_sig_invalid'], 401),
                'wordpress-missing-route' => Http::response(['code' => 'rest_no_route'], 404),
                default => Http::response($this->wordpressUser()),
            };
        });
        $attempt = (string) Str::uuid();
        app(LoginProgress::class)->begin($attempt);
        $context = $first === 'wordpress' ? ['consumer' => 'musicteacher'] : [];

        $this->assertSame('migrated-user', (new LegacyMigrationService($directory))->resolve('person@example.com', 'private-password', $context));
        $steps = app(LoginProgress::class)->read($attempt)['steps'];
        $skipped = array_values(array_filter($steps, fn ($step) => $step['stage'] === $first.'-unavailable'));
        $this->assertCount(1, $skipped);
        $this->assertSame('unavailable', $skipped[0]['state']);
        $this->assertContains($expected, array_column($steps, 'stage'));
        $this->assertStringNotContainsString('private-', json_encode($steps));
        $this->assertSame('migration', $steps[array_key_last($steps)]['stage']);
    }

    public static function providerFailures(): array
    {
        return [
            'Cloud token failure' => ['cloud-token', 'cloud'],
            'Cloud rejects service credentials with null token and timestamps' => ['cloud-invalid-service-credentials', 'cloud'],
            'Cloud HTTP failure' => ['cloud-http', 'cloud'],
            'Cloud timeout' => ['cloud-connection', 'cloud'],
            'Cloud service authorization failure' => ['cloud-authorization', 'cloud'],
            'Cloud invalid identity' => ['cloud-invalid-identity', 'cloud'],
            'MusicTeacher HTTP failure' => ['wordpress-http', 'wordpress'],
            'MusicTeacher security lockout' => ['wordpress-lockout', 'wordpress'],
            'MusicTeacher timeout' => ['wordpress-connection', 'wordpress'],
            'MusicTeacher malformed response' => ['wordpress-malformed', 'wordpress'],
            'MusicTeacher rejected service signature' => ['wordpress-signature', 'wordpress'],
            'MusicTeacher endpoint missing' => ['wordpress-missing-route', 'wordpress'],
        ];
    }

    public function test_no_match_plus_outage_names_the_service_that_could_not_be_checked(): void
    {
        $directory = $this->enable();
        $directory->shouldNotReceive('provision');
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response([], 503),
            'https://wordpress.example.test/verify' => Http::response(['ok' => false], 401),
        ]);
        $this->expectExceptionMessage('We couldn’t find a matching account in the services we could check. RSL Cloud is temporarily unavailable. Please try again in a little while.');

        (new LegacyMigrationService($directory))->resolve('person@example.com', 'private-password');
    }

    public function test_cloud_credential_rejection_stops_before_musicteacher_and_is_shown_in_progress(): void
    {
        $directory = $this->enable();
        $directory->shouldNotReceive('provision');
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'private-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['authenticated' => false], 401, ['WWW-Authenticate' => 'Bearer']),
            'https://wordpress.example.test/verify' => Http::response($this->wordpressUser()),
        ]);
        $attempt = (string) Str::uuid();
        $message = 'Incorrect credentials.';

        $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'private-password', 'login_progress' => $attempt])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', $message)->assertSessionMissing('auth.tokens');

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://wordpress.example.test/verify');
        $steps = app(LoginProgress::class)->read($attempt)['steps'];
        $this->assertSame(['cognito', 'cloud', 'cloud-credentials-rejected'], array_column($steps, 'stage'));
        $this->assertSame('rejected', $steps[2]['state']);
        $this->assertSame($message, $steps[2]['message']);
    }

    public function test_all_outages_are_reported_after_both_providers_are_checked(): void
    {
        $directory = $this->enable();
        $directory->shouldNotReceive('provision');
        Http::fake(['*' => Http::response([], 503)]);
        try {
            (new LegacyMigrationService($directory))->resolve('person@example.com', 'private-password');
            $this->fail('Unavailable providers cannot authenticate anyone.');
        } catch (RuntimeException $exception) {
            $this->assertSame('We couldn’t check your account. RSL Cloud and MusicTeacher are temporarily unavailable. Please try again in a little while.', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_a_provisioning_failure_does_not_attempt_another_provider(): void
    {
        $directory = $this->enable();
        $directory->shouldReceive('provision')->once()->andThrow(new RuntimeException('Account migration could not be completed.'));
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'private-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response($this->cloudUser()),
        ]);
        try {
            (new LegacyMigrationService($directory))->resolve('person@example.com', 'private-password');
            $this->fail('Provisioning failure must be reported without retrying creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Account migration could not be completed.', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }
}
