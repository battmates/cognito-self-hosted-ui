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
        config(['migration.enabled' => true, 'sso.management_writes_enabled' => true, 'migration.providers' => ['wordpress' => ['enabled' => true, 'endpoint' => 'https://legacy.example.com/verify', 'token' => 'api-token']]]);
    }

    private function identity(array $overrides = []): array
    {
        return ['authenticated' => true, 'user' => array_merge(['id' => '123', 'username' => 'legacy-user', 'email' => 'person@example.com', 'email_verified' => true, 'given_name' => 'Example', 'family_name' => 'User'], $overrides)];
    }

    public function test_disabled_migrations_do_not_contact_legacy_or_cognito_directory(): void
    {
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldNotReceive('hasIdentity');
        $this->assertSame('person@example.com', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertNothingSent();
    }

    public function test_existing_pool_identity_never_uses_legacy_password(): void
    {
        $this->enable();
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->with('person@example.com')->andReturn(true);
        $directory->shouldNotReceive('provision');
        $this->assertSame('person@example.com', (new LegacyMigrationService($directory))->resolve('person@example.com', 'password'));
        Http::assertNothingSent();
    }

    public function test_username_and_email_both_migrate_only_verified_identity_fields(): void
    {
        $this->enable();
        Http::fake(['https://legacy.example.com/verify' => Http::response($this->identity(['role' => 'administrator', 'custom:user_role' => 'admin']))]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->andReturn(false);
        $directory->shouldReceive('provision')->twice()->with(\Mockery::on(fn ($identity) => $identity['username'] === 'legacy-user' && ! isset($identity['role']) && ! isset($identity['custom:user_role'])), 'the-password')->andReturn('legacy-user');
        $service = new LegacyMigrationService($directory);
        $this->assertSame('legacy-user', $service->resolve('legacy-user', 'the-password'));
        $this->assertSame('legacy-user', $service->resolve('person@example.com', 'the-password'));
        Http::assertSent(fn ($request) => $request['identifier'] === 'person@example.com' && $request['password'] === 'the-password' && $request->hasHeader('Authorization', 'Bearer api-token'));
    }

    public function test_duplicate_legacy_matches_require_manual_resolution(): void
    {
        $this->enable();
        config(['migration.providers.cloud' => ['enabled' => true, 'endpoint' => 'https://cloud.example.com/verify', 'token' => 'cloud-token']]);
        Http::fake(['*' => Http::response($this->identity())]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->andReturn(false);
        $directory->shouldNotReceive('provision');
        $this->expectExceptionMessage('More than one legacy account matched');
        (new LegacyMigrationService($directory))->resolve('person@example.com', 'password');
    }

    public function test_provider_outage_is_not_treated_as_absent_user(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response([], 503)]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->andReturn(false);
        $directory->shouldNotReceive('provision');
        $this->expectExceptionMessage('Legacy account verification is unavailable');
        (new LegacyMigrationService($directory))->resolve('person@example.com', 'password');
    }

    public function test_unverified_legacy_email_cannot_create_verified_cognito_account(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response($this->identity(['email_verified' => false]))]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->andReturn(false);
        $directory->shouldNotReceive('provision');
        $this->expectExceptionMessage('Please verify your email');
        (new LegacyMigrationService($directory))->resolve('person@example.com', 'password');
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
