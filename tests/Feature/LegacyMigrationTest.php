<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use App\Services\LegacyMigrationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyMigrationTest extends TestCase
{
    private function enable(): void
    {
        config(['migration.enabled' => true, 'sso.management_writes_enabled' => true, 'migration.providers' => [
            'musicteacher' => ['driver' => 'wordpress_hmac', 'enabled' => true, 'endpoint' => 'https://legacy.example.com/verify', 'secret' => 'shared-secret'],
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
        $directory->shouldReceive('provision')->twice()->with(\Mockery::on(fn ($identity) => $identity['username'] === 'wp_123' && $identity['preferred_username'] === 'legacy-user' && ! isset($identity['role']) && ! isset($identity['custom:user_role'])), 'the-password')->andReturn('wp_123');
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
        config(['migration.providers.cloud' => ['driver' => 'json_bearer', 'enabled' => true, 'endpoint' => 'https://cloud.example.com/verify', 'token' => 'cloud-token']]);
        Http::fake([
            'https://legacy.example.com/verify' => Http::response(['ok' => false], 401),
            'https://cloud.example.com/verify' => Http::response($this->identity()),
        ]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provision')->once()->with(\Mockery::on(fn ($identity) => $identity['source'] === 'cloud'), 'password')->andReturn('legacy-user');
        $this->assertSame('legacy-user', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example.com/verify'
            && $request['identifier'] === 'person@example.com' && $request->hasHeader('Authorization', 'Bearer cloud-token'));
    }

    public function test_provider_outage_is_not_treated_as_absent_user(): void
    {
        $this->enable();
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
        config(['migration.providers' => ['cloud' => ['driver' => 'json_bearer', 'enabled' => true, 'endpoint' => 'https://cloud.example.com/verify', 'token' => 'cloud-token']]]);
        Http::fake(['*' => Http::response($this->identity(['email_verified' => false]))]);
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
        $directory->shouldReceive('provision')->once()->with(\Mockery::on(fn ($identity) => $identity['given_name'] === 'Unknown' && $identity['family_name'] === 'User'), 'password')->andReturn('wp_123');
        $this->assertSame('wp_123', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
    }

    public function test_provisioning_never_adopts_a_pool_account_created_during_verification(): void
    {
        $this->enable();
        $directory = \Mockery::mock(CognitoDirectory::class)->makePartial();
        $directory->shouldReceive('hasIdentity')->with('legacy-user')->andReturn(true);
        $this->expectExceptionMessage('An account already exists');
        $directory->provision($this->identity()['user'], 'password');
    }
}
