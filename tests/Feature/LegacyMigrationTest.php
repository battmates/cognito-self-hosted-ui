<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use App\Services\LegacyMigrationService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class LegacyMigrationTest extends TestCase
{
    private function enable(): void
    {
        config(['migration.enabled' => true, 'sso.management_writes_enabled' => true, 'migration.providers' => [
            'wordpress' => ['driver' => 'wordpress_hmac', 'enabled' => true, 'endpoint' => 'https://legacy.example.com/verify', 'secret' => 'shared-secret'],
        ]]);
    }

    private function identity(array $overrides = []): array
    {
        return ['authenticated' => true, 'user' => array_merge(['id' => '123', 'username' => 'legacy-user', 'email' => 'person@example.com', 'email_verified' => true, 'given_name' => 'Example', 'family_name' => 'User'], $overrides)];
    }

    private function wordpressIdentity(array $overrides = []): array
    {
        return ['ok' => true, 'user' => array_merge(['id' => 123, 'user_login' => 'legacy-user', 'email' => 'person@example.com', 'first_name' => 'Example', 'last_name' => 'User', 'display_name' => 'Example User'], $overrides)];
    }

    public function test_disabled_migrations_do_not_contact_legacy_or_cognito_directory(): void
    {
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldNotReceive('resolveUsername');
        $this->assertSame('person@example.com', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertNothingSent();
    }

    public function test_existing_pool_identity_never_uses_legacy_password(): void
    {
        $this->enable();
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->with('person@example.com')->andReturn('wp_123');
        $directory->shouldNotReceive('provision');
        $this->assertSame('wp_123', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertNothingSent();
    }

    public function test_username_and_email_both_migrate_only_verified_identity_fields(): void
    {
        $this->enable();
        Http::fake(['https://legacy.example.com/verify' => Http::response($this->wordpressIdentity(['role' => 'administrator', 'custom:user_role' => 'admin']))]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provision')->twice()->with(Mockery::on(fn ($identity) => $identity['username'] === 'wp_123' && $identity['preferred_username'] === 'legacy-user' && ! isset($identity['role']) && ! isset($identity['custom:user_role'])), 'the-password')->andReturn('wp_123');
        $service = new LegacyMigrationService($directory);
        $this->assertSame('wp_123', $service->resolve('legacy-user', 'the-password'));
        $this->assertSame('wp_123', $service->resolve('person@example.com', 'the-password'));
        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Cognito-Migrate-Timestamp')[0] ?? '';
            $body = $request->body();

            return json_decode($body, true) === ['username' => 'person@example.com', 'password' => 'the-password']
                && $request->hasHeader('X-Cognito-Migrate-Signature', hash_hmac('sha256', $timestamp.'.'.$body, 'shared-secret'));
        });
    }

    public function test_providers_are_checked_in_order_and_stop_after_the_first_match(): void
    {
        $this->enable();
        config(['migration.providers.cloud' => ['enabled' => true, 'base_url' => 'https://cloud.example.com', 'username' => 'service-user', 'password' => 'service-password']]);
        Http::fake([
            'https://legacy.example.com/verify' => Http::response(['ok' => false], 401),
            'https://cloud.example.com/generate-token*' => Http::response(['Token' => 'cloud-token', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.com/backstage/ssoauthenticate' => Http::response($this->identity()),
        ]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provision')->once()->with(Mockery::on(fn ($identity) => $identity['source'] === 'cloud'), 'password')->andReturn('legacy-user');
        $this->assertSame('legacy-user', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example.com/backstage/ssoauthenticate'
            && $request['identifier'] === 'person@example.com' && $request->hasHeader('Authorization', 'Bearer cloud-token'));
    }

    public function test_wordpress_success_is_verified_once_and_skips_cloud(): void
    {
        $this->enable();
        config(['migration.providers.cloud' => ['enabled' => true]]);
        Http::fake(['https://legacy.example.com/verify' => Http::response($this->wordpressIdentity())]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provision')->once()->andReturn('wp_123');

        $this->assertSame('wp_123', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertSentCount(1);
    }

    public function test_cloud_email_username_is_passed_to_provisioning_with_verified_names(): void
    {
        $this->enable();
        config(['migration.providers.cloud' => ['enabled' => true, 'base_url' => 'https://cloud.example.com', 'username' => 'service-user', 'password' => 'service-password']]);
        $username = 'example.osullivan';
        Http::fake([
            'https://legacy.example.com/verify' => Http::response([], 404),
            'https://cloud.example.com/generate-token*' => Http::response(['Token' => 'cloud-token', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.com/backstage/ssoauthenticate' => Http::response([
                'authenticated' => true,
                'user' => [
                    'id' => 'source-user-123', 'username' => 'person@example.com', 'email' => 'person@example.com',
                    'email_verified' => true, 'given_name' => 'Example', 'family_name' => "O'Sullivan",
                ],
            ]),
        ]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->with('person@example.com')->andReturn(null);
        $directory->shouldReceive('provision')->twice()->with([
            'source' => 'cloud', 'id' => 'source-user-123', 'username' => 'person@example.com', 'email' => 'person@example.com',
            'given_name' => 'Example', 'family_name' => "O'Sullivan",
        ], 'password')->andReturn($username);

        $service = new LegacyMigrationService($directory);
        $this->assertSame($username, $service->resolve('person@example.com', 'password'));
        $this->assertSame($username, $service->resolve('person@example.com', 'password'));
        Http::assertSentCount(5);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'GET'));
    }

    public function test_provider_outage_is_not_treated_as_absent_user(): void
    {
        $this->enable();
        config(['migration.providers.cloud' => ['enabled' => true]]);
        Http::fake(['*' => Http::response([], 503)]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldNotReceive('provision');
        $this->expectExceptionMessage('Legacy account verification is unavailable');
        (new LegacyMigrationService($directory))->resolve('person@example.com', 'password');
    }

    public function test_unverified_legacy_email_cannot_create_verified_cognito_account(): void
    {
        $this->enable();
        config(['migration.providers' => ['cloud' => ['enabled' => true, 'base_url' => 'https://cloud.example.com', 'username' => 'service-user', 'password' => 'service-password']]]);
        Http::fake([
            'https://cloud.example.com/generate-token*' => Http::response(['Token' => 'cloud-token', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.com/backstage/ssoauthenticate' => Http::response($this->identity(['email_verified' => false])),
        ]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldNotReceive('provision');
        $this->expectExceptionMessage('Please verify your email');
        (new LegacyMigrationService($directory))->resolve('person@example.com', 'password');
    }

    public function test_existing_pool_user_can_sign_in_when_migration_writes_are_disabled(): void
    {
        $this->enable();
        config(['sso.management_writes_enabled' => false]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->with('person@example.com')->once()->andReturn('wp_123');
        $this->assertSame('wp_123', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertNothingSent();
    }

    public function test_legacy_username_resolves_an_account_previously_created_by_the_lambda(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response($this->wordpressIdentity())]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->with('legacy-user')->once()->andReturn(null);
        $directory->shouldReceive('resolveUsername')->with('wp_123')->once()->andReturn('wp_123');
        $directory->shouldNotReceive('provision');
        $this->assertSame('wp_123', (new LegacyMigrationService($directory))->resolve('legacy-user', 'password'));
    }

    public function test_blank_wordpress_names_are_not_required_for_migration(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response($this->wordpressIdentity(['first_name' => '', 'last_name' => '']))]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provision')->once()->with(Mockery::on(fn ($identity) => $identity['given_name'] === 'Unknown' && $identity['family_name'] === 'User'), 'password')->andReturn('wp_123');
        $this->assertSame('wp_123', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
    }

    public function test_provisioning_never_adopts_a_pool_account_created_during_verification(): void
    {
        $this->enable();
        $directory = Mockery::mock(CognitoDirectory::class)->makePartial();
        $directory->shouldReceive('hasIdentity')->with('legacy-user')->andReturn(true);
        $this->expectExceptionMessage('An account already exists');
        $directory->provision($this->identity()['user'], 'password');
    }

    public function test_password_recovery_uses_the_signed_wordpress_lookup_and_then_provisions_a_role_free_account(): void
    {
        config([
            'migration.password_recovery_enabled' => true,
            'migration.providers.wordpress' => [
                'enabled' => true,
                'lookup_endpoint' => 'https://wordpress.example.test/wp-json/cognito-migrate/v1/lookup',
                'token' => 'shared-secret',
            ],
        ]);
        Http::fake(function ($request) {
            $body = '{"username":"missing@example.com"}';
            $this->assertSame($body, $request->body());
            $timestamp = $request->header('X-Cognito-Migrate-Timestamp')[0];
            $this->assertMatchesRegularExpression('/^\d+$/', $timestamp);
            $this->assertSame(hash_hmac('sha256', $timestamp.'.'.$body, 'shared-secret'), $request->header('X-Cognito-Migrate-Signature')[0]);

            return Http::response(['ok' => true, 'user' => [
                'id' => 123, 'user_login' => 'wordpress-user', 'email' => 'person@example.com', 'first_name' => 'Example', 'last_name' => 'User',
            ]]);
        });
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->with('wordpress-user')->once()->andReturn(false);
        $directory->shouldReceive('hasIdentity')->with('person@example.com')->once()->andReturn(false);
        $directory->shouldReceive('provisionForPasswordRecovery')->once()->with(Mockery::on(fn ($identity) => $identity === [
            'source' => 'wordpress', 'id' => '123', 'username' => 'wordpress-user', 'email' => 'person@example.com', 'given_name' => 'Example', 'family_name' => 'User',
        ]))->andReturn('wordpress-user');

        $this->assertSame('wordpress-user', (new LegacyMigrationService($directory))->recoverForPasswordReset('missing@example.com'));
    }

    public function test_password_recovery_returns_no_match_for_a_wordpress_lookup_not_found(): void
    {
        config(['migration.password_recovery_enabled' => true, 'migration.providers.wordpress' => [
            'enabled' => true, 'lookup_endpoint' => 'https://wordpress.example.test/lookup', 'token' => 'shared-secret',
        ]]);
        Http::fake(['*' => Http::response(['ok' => false], 404)]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldNotReceive('hasIdentity');

        $this->assertNull((new LegacyMigrationService($directory))->recoverForPasswordReset('missing@example.com'));
    }
}
