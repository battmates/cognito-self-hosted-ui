<?php

namespace Tests\Feature;

use App\Exceptions\MigrationPasswordRequiredException;
use App\Services\CognitoDirectory;
use App\Services\CognitoIdentityService;
use App\Services\LegacyMigrationService;
use App\Services\MigrationPasswordChallenge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MigrationPasswordChallengeTest extends TestCase
{
    private function identity(): array
    {
        return ['source' => 'cloud', 'id' => 'cloud-123', 'username' => 'person@example.com', 'email' => 'person@example.com', 'given_name' => 'Example', 'family_name' => 'User'];
    }

    private function policy(): array
    {
        return ['minimum_length' => 8, 'uppercase' => true, 'lowercase' => true, 'numbers' => true, 'symbols' => true];
    }

    private function directory(): CognitoDirectory
    {
        config(['migration.enabled' => true, 'sso.management_writes_enabled' => true]);
        $directory = $this->mock(CognitoDirectory::class);
        $directory->shouldReceive('migrationPasswordPolicy')->andReturn($this->policy());

        return $directory;
    }

    private function startHttp(): array
    {
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()
            ->with('person@example.com', 'legacy-password', Mockery::any())
            ->andThrow(new MigrationPasswordRequiredException($this->identity()));
        $response = $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'legacy-password']);
        $response->assertOk()->assertJsonPath('password_required', true)->assertJsonPath('provider_name', 'Cloud');
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
        }

        $this->withCredentials();

        return $response->json();
    }

    public function test_verified_identity_is_preserved_only_after_a_pre_creation_policy_rejection(): void
    {
        $directory = $this->directory();
        config(['migration.providers' => ['cloud' => ['enabled' => true, 'base_url' => 'https://cloud.example.test', 'username' => 'service', 'password' => 'service-password']]]);
        $directory->shouldReceive('resolveUsername')->andReturn(null);
        $directory->shouldReceive('provision')->once()->andThrow(new MigrationPasswordRequiredException);
        Http::fake([
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'bearer', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response(['authenticated' => true, 'user' => ['id' => 'cloud-123', 'username' => 'person@example.com', 'email' => 'person@example.com', 'email_verified' => true, 'given_name' => 'Example', 'family_name' => 'User']]),
        ]);
        try {
            app(LegacyMigrationService::class)->resolve('person@example.com', 'legacy-password');
            $this->fail('A new password should be requested.');
        } catch (MigrationPasswordRequiredException $exception) {
            $this->assertSame($this->identity(), $exception->identity);
            $this->assertStringNotContainsString('legacy-password', serialize($exception->identity));
        }
        Http::assertSentCount(2);
    }

    public function test_a_new_confirmed_password_creates_the_account_and_signs_in_without_legacy_reverification(): void
    {
        $directory = $this->directory();
        $directory->shouldReceive('provision')->once()->with($this->identity(), 'NewPassword123!')->andReturn('canonical-user');
        $setup = $this->startHttp();
        app(CognitoIdentityService::class)->shouldReceive('loginWithCognito')->once()
            ->with('canonical-user', 'NewPassword123!', Mockery::on(fn ($context) => $context['portal_request'] === 'direct'))
            ->andReturn(['user' => ['username' => 'canonical-user'], 'tokens' => ['id_token' => 'private-token']]);

        $response = $this->postJson('/login/migration-password', ['challenge' => $setup['challenge_id'], 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
            'identity' => ['email' => 'untrusted@example.com'], 'consumer' => 'untrusted',
        ]);

        $response->assertOk()->assertExactJson(['redirect' => route('portal.home')])->assertSessionHas('auth.status.authenticated', true);
        $this->assertStringNotContainsString('private-token', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_sign_in_failure_after_creation_explains_how_to_continue_and_does_not_create_again(): void
    {
        $directory = $this->directory();
        $directory->shouldReceive('provision')->once()->andReturn('canonical-user');
        $setup = $this->startHttp();
        app(CognitoIdentityService::class)->shouldReceive('loginWithCognito')->once()->andThrow(new RuntimeException('Sign-in service unavailable.'));
        $input = ['challenge' => $setup['challenge_id'], 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];

        $this->postJson('/login/migration-password', $input)->assertUnprocessable()->assertJsonPath('restart_required', true)
            ->assertJsonPath('errors.password.0', 'Your single sign-on account was created, but sign-in could not be completed. Please sign in with your new password.');
        $this->postJson('/login/migration-password', $input)->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    public function test_confirmation_and_strength_errors_do_not_create_an_account(): void
    {
        $directory = $this->directory();
        $directory->shouldNotReceive('provision');
        $setup = $this->startHttp();
        $this->postJson('/login/migration-password', ['challenge' => $setup['challenge_id'], 'password' => 'NewPassword123!', 'password_confirmation' => 'DifferentPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/login/migration-password', ['challenge' => $setup['challenge_id'], 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_pending_proof_is_encrypted_session_bound_and_expires(): void
    {
        $directory = $this->directory();
        $directory->shouldNotReceive('provision');
        $flow = app(MigrationPasswordChallenge::class);
        $setup = $flow->start('session-one', $this->identity(), ['consumer' => 'cloud'], null);
        $key = 'portal.migration.password.'.hash('sha256', 'session-one:'.$setup['challenge_id']);
        $stored = Cache::get($key);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('person@example.com', $stored);
        $this->assertStringNotContainsString('identity', $stored);
        try {
            $flow->show('session-two', $setup['challenge_id']);
            $this->fail('Another session must not use this proof.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('challenge', $exception->errors());
        }
        $this->travel(6)->minutes();
        $this->expectException(ValidationException::class);
        $flow->show('session-one', $setup['challenge_id']);
    }

    public function test_proof_is_one_use_and_a_provisioning_failure_cannot_retry_creation(): void
    {
        $directory = $this->directory();
        $directory->shouldReceive('provision')->once()->andThrow(new RuntimeException('A partial account needs review.'));
        $flow = app(MigrationPasswordChallenge::class);
        $setup = $flow->start('session-one', $this->identity(), [], null);
        try {
            $flow->complete('session-one', $setup['challenge_id'], 'NewPassword123!');
            $this->fail('The write failure must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A partial account needs review.', $exception->getMessage());
        }
        $this->expectException(ValidationException::class);
        $flow->complete('session-one', $setup['challenge_id'], 'NewPassword123!');
    }

    public function test_a_definite_policy_rejection_allows_a_stronger_password_retry(): void
    {
        $directory = $this->directory();
        $directory->shouldReceive('provision')->once()->with($this->identity(), 'NewPassword123!')->andThrow(new MigrationPasswordRequiredException);
        $directory->shouldReceive('provision')->once()->with($this->identity(), 'EvenStrongerPassword123!')->andReturn('canonical-user');
        $flow = app(MigrationPasswordChallenge::class);
        $setup = $flow->start('session-one', $this->identity(), [], null);
        try {
            $flow->complete('session-one', $setup['challenge_id'], 'NewPassword123!');
            $this->fail('A policy rejection must request another password.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertSame('canonical-user', $flow->complete('session-one', $setup['challenge_id'], 'EvenStrongerPassword123!')['username']);
        $this->expectException(ValidationException::class);
        $flow->complete('session-one', $setup['challenge_id'], 'EvenStrongerPassword123!');
    }

    public function test_the_original_application_journey_is_kept_and_expired_journeys_cannot_create_accounts(): void
    {
        $directory = $this->directory();
        $directory->shouldReceive('provision')->once()->andReturn('canonical-user');
        $flow = app(MigrationPasswordChallenge::class);
        $authorization = ['consumer' => 'musicteacher', 'request_id' => 'original-journey', 'expires_at' => now()->addMinutes(10)->getTimestamp()];
        $context = ['consumer' => 'musicteacher', 'portal_request' => 'original-journey'];
        $setup = $flow->start('session-one', $this->identity(), $context, $authorization);
        $result = $flow->complete('session-one', $setup['challenge_id'], 'NewPassword123!');
        $this->assertSame($context, $result['context']);
        $this->assertSame($authorization, $result['authorization']);
        $expired = $flow->start('session-one', $this->identity(), $context, array_merge($authorization, ['expires_at' => now()->subMinute()->getTimestamp()]));
        $this->expectException(ValidationException::class);
        $flow->complete('session-one', $expired['challenge_id'], 'NewPassword123!');
    }

    public function test_an_expired_password_page_returns_to_sign_in_instead_of_looping(): void
    {
        $this->get('/login/migration-password?challenge='.Str::uuid())->assertRedirect(route('portal.login'));
    }

    public function test_password_setup_also_works_without_javascript(): void
    {
        $directory = $this->directory();
        $directory->shouldReceive('provision')->once()->with($this->identity(), 'NewPassword123!')->andReturn('canonical-user');
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()->andThrow(new MigrationPasswordRequiredException($this->identity()));
        $response = $this->post('/login', ['email' => 'person@example.com', 'password' => 'legacy-password']);
        $response->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
        }
        $url = $response->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->get($url)->assertOk()->assertSee('Create your single sign-on password');
        app(CognitoIdentityService::class)->shouldReceive('loginWithCognito')->once()->andReturn([
            'user' => ['username' => 'canonical-user'], 'tokens' => ['id_token' => 'private-token'],
        ]);

        $this->post('/login/migration-password', ['challenge' => $query['challenge'], 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])
            ->assertRedirect(route('portal.home'))->assertSessionHas('auth.status.authenticated', true);
    }
}
