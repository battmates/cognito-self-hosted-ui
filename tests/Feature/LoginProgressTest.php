<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthPortalController;
use App\Services\CognitoDirectory;
use App\Services\CognitoIdentityService;
use App\Services\LegacyMigrationService;
use App\Services\LoginProgress;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LoginProgressTest extends TestCase
{
    public function test_async_success_creates_the_session_without_exposing_tokens_in_json(): void
    {
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()->andReturn([
            'user' => ['username' => 'test-user'],
            'tokens' => ['id_token' => 'private-id-token', 'access_token' => 'private-access-token'],
        ]);

        $response = $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'private-password']);

        $response->assertOk()->assertExactJson(['redirect' => route('portal.home')])
            ->assertSessionHas('auth.status.authenticated', true)
            ->assertSessionHas('auth.tokens.id_token', 'private-id-token');
    }

    public function test_async_login_preserves_challenges_and_returns_the_next_step(): void
    {
        $attempt = (string) Str::uuid();
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()->andReturn([
            'challenge' => ['name' => 'SOFTWARE_TOKEN_MFA', 'username' => 'test-user', 'session' => 'private-challenge'],
        ]);

        $response = $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'private-password', 'login_progress' => $attempt]);

        $response->assertOk()->assertSessionHas('portal.challenge.name', 'SOFTWARE_TOKEN_MFA')->assertSessionMissing('auth.tokens');
        $this->assertStringContainsString('/challenge', $response->json('redirect'));
        $this->assertStringNotContainsString('private-challenge', $response->getContent());
    }

    public function test_musicteacher_priority_comes_from_the_validated_journey(): void
    {
        config(['services.cognito.client_id' => 'test-client', 'services.cognito.client_secret' => 'test-secret',
            'sso.consumers.musicteacher.callback_urls' => ['https://musicteacher.example.test/callback'],
        ]);
        $this->get('/login?'.http_build_query(['client_id' => 'test-client', 'response_type' => 'code', 'redirect_uri' => 'https://musicteacher.example.test/callback']))->assertOk();
        $journey = session('portal.authorization.request_id');
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()
            ->with('person@example.com', 'private-password', Mockery::on(fn ($context) => $context['consumer'] === 'musicteacher' && $context['portal_request'] === $journey))
            ->andReturn(['challenge' => ['name' => 'SOFTWARE_TOKEN_MFA', 'username' => 'test-user', 'session' => 'private-challenge']]);

        $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'private-password', 'portal_request' => $journey, 'consumer' => 'cloud'])
            ->assertOk()->assertSessionHas('portal.challenge.name', 'SOFTWARE_TOKEN_MFA');
    }

    public function test_failure_keeps_validation_errors_and_progress_contains_no_credentials(): void
    {
        $attempt = (string) Str::uuid();
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()->andThrow(new RuntimeException('Cloud account verification is unavailable.'));
        $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'private-password', 'login_progress' => $attempt])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $url = URL::temporarySignedRoute('portal.login.progress', now()->addMinute(), ['attempt' => $attempt]);
        $response = $this->getJson($url)->assertOk()->assertJsonPath('steps.0.stage', 'cognito');
        $this->assertStringNotContainsString('private-password', $response->getContent());
        $this->assertStringNotContainsString('person@example.com', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertCount(0, $response->headers->getCookies());
    }

    public function test_progress_requires_an_unexpired_signed_url(): void
    {
        $attempt = (string) Str::uuid();
        $this->getJson('/login/progress/'.$attempt)->assertForbidden();
        $expired = URL::temporarySignedRoute('portal.login.progress', now()->subMinute(), ['attempt' => $attempt]);
        $this->getJson($expired)->assertForbidden();
    }

    public function test_a_second_sign_in_cannot_start_while_the_first_is_running(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(false);
        $lock->shouldNotReceive('release');
        Cache::shouldReceive('lock')->once()->with(Mockery::on(fn ($key) => str_starts_with($key, 'portal.login.active.')), 180)->andReturn($lock);
        $this->mock(CognitoIdentityService::class)->shouldNotReceive('login');
        $request = Request::create('/login', 'POST', ['email' => 'person@example.com', 'password' => 'private-password']);
        $request->setLaravelSession(app('session')->driver());
        try {
            app(AuthPortalController::class)->storeLogin($request);
            $this->fail('A duplicate sign-in must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['email' => ['A sign-in is already in progress. Please wait for it to finish.']], $exception->errors());
        }
    }

    public function test_a_failed_sign_in_releases_its_lock_for_a_retry(): void
    {
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->twice()->andThrow(new RuntimeException('Invalid sign-in.'));
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'private-password'])
                ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Invalid sign-in.');
        }
    }

    #[DataProvider('providerOrders')]
    public function test_real_provider_fallback_reports_only_the_checks_that_run(array $context, array $expected, int $cloudStatus = 200): void
    {
        config(['migration.enabled' => true, 'sso.management_writes_enabled' => true,
            'migration.providers.wordpress' => ['enabled' => true, 'driver' => 'json_bearer', 'endpoint' => 'https://wordpress.example.test/verify', 'token' => 'private-service-token'],
            'migration.providers.cloud' => ['enabled' => true, 'base_url' => 'https://cloud.example.test', 'username' => 'service-user', 'password' => 'private-service-password'],
        ]);
        $this->mock(CognitoDirectory::class)->shouldReceive('resolveUsername')->once()->andReturn(null);
        Http::fake([
            'https://wordpress.example.test/verify' => Http::response([], 404),
            'https://cloud.example.test/Account/generate-token*' => Http::response(['Token' => 'private-token', 'ExpireTime' => now()->addHour()->toIso8601String()]),
            'https://cloud.example.test/backstage/ssoauthenticate' => Http::response($cloudStatus === 401 ? ['message' => 'Invalid credentials.'] : ['authenticated' => false, 'user' => null], $cloudStatus),
        ]);
        $attempt = (string) Str::uuid();
        app(LoginProgress::class)->begin($attempt);
        try {
            app(LegacyMigrationService::class)->resolve('person@example.com', 'private-password', $context);
            $this->fail('Unmatched credentials should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Incorrect email, username, or password.', $exception->getMessage());
        }
        $steps = app(LoginProgress::class)->read($attempt)['steps'];
        $this->assertSame($expected, array_column($steps, 'stage'));
        $this->assertSame([12, 35, 45, 60, 70], array_column($steps, 'percent'));
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->method())->all();
        $this->assertSame(($context['consumer'] ?? null) === 'musicteacher' ? ['POST', 'GET', 'POST'] : ['GET', 'POST', 'POST'], $requests);
        $this->assertStringNotContainsString('private-', json_encode($steps));
    }

    public static function providerOrders(): array
    {
        return [
            'direct login' => [[], ['cognito', 'cloud', 'cloud-no-match', 'wordpress', 'wordpress-no-match']],
            'nonexistent user with Cloud 401' => [[], ['cognito', 'cloud', 'cloud-no-match', 'wordpress', 'wordpress-no-match'], 401],
            'MusicTeacher login' => [['consumer' => 'musicteacher'], ['cognito', 'wordpress', 'wordpress-no-match', 'cloud', 'cloud-no-match']],
        ];
    }
}
