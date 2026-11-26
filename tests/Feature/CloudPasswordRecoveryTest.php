<?php

namespace Tests\Feature;

use App\Services\CloudMigrationClient;
use App\Services\CognitoDirectory;
use App\Services\CognitoIdentityService;
use App\Services\LegacyMigrationService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CloudPasswordRecoveryTest extends TestCase
{
    private function provider(): array
    {
        return ['enabled' => true, 'base_url' => 'https://cloud.example.test/api', 'username' => 'service-user', 'password' => 'service-password'];
    }

    private function user(): array
    {
        return ['id' => '123', 'username' => 'cloud-user', 'email' => 'person@example.com', 'email_verified' => true, 'given_name' => 'Example', 'family_name' => 'User'];
    }

    private function fakeRecovery(array $response, int $status = 200): void
    {
        config(['migration.password_recovery_enabled' => true, 'migration.providers.cloud' => $this->provider(), 'migration.providers.wordpress.enabled' => false]);
        Http::fake([
            'https://cloud.example.test/api/Account/generate-token*' => Http::response(['Token' => 'service-bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/api/backstage/ssoauthenticate' => Http::response($response, $status),
            'https://cognito-idp.eu-west-2.amazonaws.com/' => Http::response(['CodeDeliveryDetails' => ['DeliveryMedium' => 'EMAIL']]),
            'https://wordpress.example.test/lookup' => Http::response(['ok' => true, 'user' => ['id' => 456, 'user_login' => 'wordpress-user', 'email' => 'person@example.com', 'first_name' => 'Example', 'last_name' => 'User']]),
        ]);
    }

    public function test_live_cloud_payload_reaches_recovery_provisioning_and_cognito_reset_request(): void
    {
        config(['services.cognito.region' => 'eu-west-2', 'services.cognito.client_id' => 'test-client', 'services.cognito.client_secret' => 'test-secret']);
        $this->fakeRecovery(['authenticated' => false, 'user' => $this->user() + ['role' => 'administrator']]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('resolveUsername')->with('cloud-user')->once()->andReturn(null);
        $directory->shouldReceive('resolveUsername')->with('person@example.com')->once()->andReturn(null);
        $directory->shouldReceive('provisionForPasswordRecovery')->once()->with(Mockery::on(fn ($identity) => $identity === [
            'source' => 'cloud', 'id' => '123', 'username' => 'cloud-user', 'email' => 'person@example.com', 'given_name' => 'Example', 'family_name' => 'User',
        ]))->andReturn('cloud-user');

        app(CognitoIdentityService::class)->startForgotPassword('person@example.com');

        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example.test/api/backstage/ssoauthenticate'
            && $request->data() === ['identifier' => 'person@example.com', 'purpose' => 'password_recovery']
            && $request->hasHeader('Authorization', 'Bearer service-bearer'));
        Http::assertSent(fn ($request) => $request->url() === 'https://cognito-idp.eu-west-2.amazonaws.com/'
            && $request->header('X-Amz-Target')[0] === 'AWSCognitoIdentityProviderService.ForgotPassword'
            && $request['Username'] === 'cloud-user');
        Http::assertSentCount(3);
    }

    public function test_explicit_found_flag_remains_supported(): void
    {
        $this->fakeRecovery(['found' => true, 'authenticated' => false, 'user' => $this->user()]);
        $this->assertSame($this->user(), app(CloudMigrationClient::class)->lookupForPasswordRecovery($this->provider(), 'person@example.com'));
    }

    public function test_cloud_not_found_still_falls_back_to_the_existing_wordpress_payload(): void
    {
        $this->fakeRecovery(['authenticated' => false, 'user' => null], 404);
        config(['migration.providers.wordpress' => ['enabled' => true, 'lookup_endpoint' => 'https://wordpress.example.test/lookup', 'secret' => 'shared-secret']]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provisionForPasswordRecovery')->once()->with(Mockery::on(fn ($identity) => $identity === [
            'source' => 'wordpress', 'id' => '456', 'username' => 'wordpress-user', 'email' => 'person@example.com', 'given_name' => 'Example', 'family_name' => 'User', 'preferred_username' => 'wordpress-user',
        ]))->andReturn('wordpress-user');

        $this->assertSame('wordpress-user', app(LegacyMigrationService::class)->recoverForPasswordReset('person@example.com'));
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://wordpress.example.test/lookup') return false;
            $timestamp = $request->header('X-Cognito-Migrate-Timestamp')[0];
            return $request->body() === '{"username":"person@example.com"}'
                && $request->hasHeader('X-Cognito-Migrate-Signature', hash_hmac('sha256', $timestamp.'.'.$request->body(), 'shared-secret'));
        });
    }

    #[DataProvider('invalidResponses')]
    public function test_error_or_contradictory_responses_cannot_authorize_recovery(array $overrides, int $status): void
    {
        $this->fakeRecovery(array_replace(['authenticated' => false, 'user' => $this->user()], $overrides), $status);
        $this->expectException(RuntimeException::class);
        app(CloudMigrationClient::class)->lookupForPasswordRecovery($this->provider(), 'person@example.com');
    }

    public static function invalidResponses(): array
    {
        return [
            'credential rejection with user details' => [[], 401],
            'service error with user details' => [[], 500],
            'ordinary successful login' => [['authenticated' => true], 200],
            'missing authentication marker' => [['authenticated' => null], 200],
            'contradictory found false' => [['found' => false], 200],
            'explicit null found marker' => [['found' => null], 200],
            'invalid user' => [['user' => 'invalid'], 200],
        ];
    }

    #[DataProvider('invalidIdentities')]
    public function test_identity_checks_still_block_provisioning(array $overrides): void
    {
        $this->fakeRecovery(['authenticated' => false, 'user' => array_replace($this->user(), $overrides)]);
        $this->mock(CognitoDirectory::class)->shouldNotReceive('provisionForPasswordRecovery');
        $this->expectException(RuntimeException::class);
        app(LegacyMigrationService::class)->recoverForPasswordReset('person@example.com');
    }

    public static function invalidIdentities(): array
    {
        return [
            'different email' => [['email' => 'another@example.com']],
            'unverified email' => [['email_verified' => false]],
            'invalid identity ID' => [['id' => 123]],
            'missing name' => [['given_name' => '']],
        ];
    }
}
