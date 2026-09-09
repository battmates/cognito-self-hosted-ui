<?php

namespace Tests\Feature;

use App\Services\CognitoIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationBrokerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cognito.client_id' => 'shared-client', 'services.cognito.client_secret' => 'client-secret']);
    }

    private function params(array $extra = []): array
    {
        return array_merge(['client_id' => 'shared-client', 'response_type' => 'code', 'redirect_uri' => 'https://staging.rockschool.io/cognito-login', 'state' => 'opaque+/=state', 'scope' => 'openid email profile'], $extra);
    }

    private function auth(): array
    {
        return ['auth' => ['status' => ['authenticated' => true, 'user' => ['email' => 'test@example.com', 'username' => 'tester', 'subject' => 'test-sub']], 'tokens' => ['client_id' => 'shared-client', 'id_token' => 'signed-cognito-id-token', 'access_token' => 'cognito-access-token', 'refresh_token' => 'private-refresh-token', 'expires_at' => time() + 3600]]];
    }

    private function issue(array $params = []): string
    {
        $response = $this->withSession($this->auth())->get('/oauth2/authorize?'.http_build_query($this->params($params)))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('opaque+/=state', $query['state']);
        $this->assertArrayNotHasKey('sso_token', $query);

        return $query['code'];
    }

    private function exchange(string $code, array $extra = [])
    {
        return $this->post('/oauth2/token', array_merge(['grant_type' => 'authorization_code', 'client_id' => 'shared-client', 'client_secret' => 'client-secret', 'redirect_uri' => 'https://staging.rockschool.io/cognito-login', 'code' => $code], $extra));
    }

    public function test_existing_consumer_can_exchange_a_single_use_code_and_state_is_opaque(): void
    {
        $code = $this->issue();
        $row = DB::table('portal_authorization_codes')->first();
        $this->assertStringNotContainsString('cognito-access-token', $row->payload);
        $this->assertNotSame($code, $row->code_hash);
        $this->exchange($code)->assertOk()->assertJsonPath('id_token', 'signed-cognito-id-token')->assertJsonMissingPath('refresh_token')->assertHeader('Cache-Control', 'no-store, private');
        $this->exchange($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[DataProvider('invalidRequests')]
    public function test_unsafe_or_unsupported_authorization_requests_fail_locally(array $params, int $status): void
    {
        $this->get('/oauth2/authorize?'.http_build_query($this->params($params)))->assertStatus($status)->assertHeaderMissing('Location');
        $this->assertDatabaseCount('portal_authorization_codes', 0);
    }

    public static function invalidRequests(): array
    {
        return [
            [['redirect_uri' => 'https://staging.rockschool.io.evil.test/cognito-login'], 400],
            [['redirect_uri' => 'https://staging.rockschool.io/other'], 400],
            [['redirect_uri' => 'http://staging.rockschool.io/cognito-login'], 400],
            [['redirect_uri' => 'https://staging.rockschool.io/cognito-login#fragment'], 400],
            [['client_id' => 'wrong-client'], 401], [['response_type' => 'token'], 400],
            [['scope' => 'openid resource/write'], 400], [['nonce' => 'unsupported'], 400],
            [['code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'plain'], 400],
        ];
    }

    public function test_bad_client_credentials_do_not_consume_code(): void
    {
        $code = $this->issue();
        $this->exchange($code, ['client_secret' => 'bad'])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->exchange($code)->assertOk();
    }

    public function test_redirect_mismatch_does_not_consume_code(): void
    {
        $code = $this->issue();
        $this->exchange($code, ['redirect_uri' => 'https://staging.guestlist.rockschool.io/cognito/callback'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->exchange($code)->assertOk();
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->issue();
        $this->travel(61)->seconds();
        $this->exchange($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_pkce_requires_correct_verifier(): void
    {
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $this->issue(['code_challenge' => $challenge, 'code_challenge_method' => 'S256']);
        $this->exchange($code, ['code_verifier' => str_repeat('b', 64)])->assertStatus(400);
        $this->exchange($code, ['code_verifier' => $verifier])->assertOk();
    }

    public function test_basic_client_authentication_is_supported(): void
    {
        $code = $this->issue();
        $this->withHeader('Authorization', 'Basic '.base64_encode('shared-client:client-secret'))->post('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->params()['redirect_uri']])->assertOk();
    }

    public function test_referral_headline_and_login_complete_the_original_callback(): void
    {
        $this->get('/login?'.http_build_query($this->params()))->assertOk()->assertSee('Please login or register to continue to Backstage');
        $id = session('portal.authorization.request_id');
        $result = ['user' => $this->auth()['auth']['status']['user'], 'tokens' => $this->auth()['auth']['tokens']];
        $this->mock(CognitoIdentityService::class)->shouldReceive('login')->once()->andReturn($result);
        $this->post('/login', ['email' => 'tester', 'password' => 'password', 'portal_request' => $id])->assertRedirectContains('https://staging.rockschool.io/cognito-login?code=');
    }

    public function test_tabs_keep_separate_application_requests(): void
    {
        $this->get('/login?'.http_build_query($this->params()));
        $backstage = session('portal.authorization.request_id');
        $this->get('/login?'.http_build_query($this->params(['redirect_uri' => 'https://staging.guestlist.rockschool.io/cognito/callback'])));
        $guestlist = session('portal.authorization.request_id');
        $this->assertNotSame($backstage, $guestlist);
        $this->get('/register?portal_request='.$backstage)->assertSee('continue to Backstage')->assertDontSee('continue to Guestlist');
    }

    public function test_prompt_none_returns_login_required_with_state_and_login_forces_form(): void
    {
        $response = $this->get('/login?'.http_build_query($this->params(['prompt' => 'none'])))->assertRedirect();
        $this->assertStringContainsString('error=login_required', $response->headers->get('Location'));
        $this->withSession($this->auth())->get('/login?'.http_build_query($this->params(['prompt' => 'login'])))->assertOk()->assertSee('Sign In');
    }

    public function test_expired_session_cannot_issue_code_when_refresh_fails(): void
    {
        $session = $this->auth();
        $session['auth']['tokens']['expires_at'] = time() - 10;
        $this->mock(CognitoIdentityService::class, function ($mock) {
            $mock->shouldReceive('refresh')->once()->andThrow(new \RuntimeException('expired'));
            $mock->shouldReceive('socialProviders')->andReturn([]);
        });
        $this->withSession($session)->get('/login?'.http_build_query($this->params()))->assertOk()->assertSessionMissing('auth.tokens');
        $this->assertDatabaseCount('portal_authorization_codes', 0);
    }

    public function test_valid_session_refresh_allows_sso(): void
    {
        $session = $this->auth();
        $session['auth']['tokens']['expires_at'] = time() - 10;
        $this->mock(CognitoIdentityService::class)->shouldReceive('refresh')->once()->andReturn(['tokens' => $this->auth()['auth']['tokens'], 'user' => $this->auth()['auth']['status']['user']]);
        $this->withSession($session)->get('/login?'.http_build_query($this->params()))->assertRedirectContains('code=');
    }

    public function test_logout_rejects_unregistered_redirect_and_valid_logout_clears_session(): void
    {
        $this->mock(CognitoIdentityService::class)->shouldReceive('logout')->once();
        $this->get('/logout?client_id=shared-client&logout_uri=https://evil.test')->assertStatus(400);
        $this->withSession($this->auth())->get('/logout?'.http_build_query(['client_id' => 'shared-client', 'logout_uri' => 'https://staging.rockschool.io/logout']))->assertRedirect('https://staging.rockschool.io/logout')->assertSessionMissing('auth.status');
    }

    public function test_direct_dashboard_has_app_links_and_no_old_referral(): void
    {
        $this->get('/login?'.http_build_query($this->params()));
        $this->withSession($this->auth())->get('/')->assertOk()->assertSee('Edit profile')->assertSee('https://staging.guestlist.rockschool.io', false)->assertSessionMissing('portal.authorization');
    }

    public function test_session_tokens_for_another_client_cannot_be_handed_off(): void
    {
        $session = $this->auth();
        $session['auth']['tokens']['client_id'] = 'another-client';
        $this->withSession($session)->get('/login?'.http_build_query($this->params()))->assertStatus(400)->assertHeaderMissing('Location');
        $this->assertDatabaseCount('portal_authorization_codes', 0);
    }

    public function test_opaque_state_keeps_whitespace_and_empty_values(): void
    {
        foreach (['  opaque state  ', ''] as $state) {
            $response = $this->withSession($this->auth())->get('/oauth2/authorize?'.http_build_query($this->params(['state' => $state])))->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame($state, $query['state']);
        }
    }

    public function test_browser_forms_require_csrf_but_token_endpoint_uses_client_credentials(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/login', ['email' => 'test', 'password' => 'secret'])->assertStatus(419);
        $this->post('/oauth2/token', ['client_id' => 'shared-client', 'client_secret' => 'wrong', 'grant_type' => 'authorization_code'])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
    }

    public function test_backstage_recovery_prompt_does_not_trap_the_user_after_reset(): void
    {
        $this->get('/login?'.http_build_query($this->params(['prompt' => 'forgot_password'])))->assertOk()->assertSee('Send reset code');
        $id = session('portal.authorization.request_id');
        $this->get('/login?portal_request='.$id)->assertOk()->assertSee('Sign In')->assertDontSee('Send reset code');
    }

    public function test_registration_and_confirmation_preserve_the_original_application_journey(): void
    {
        $result = ['user' => $this->auth()['auth']['status']['user'], 'tokens' => $this->auth()['auth']['tokens']];
        $this->mock(CognitoIdentityService::class, function ($mock) use ($result) {
            $mock->shouldReceive('socialProviders')->andReturn([]);
            $mock->shouldReceive('register')->once()->andReturn(['confirmed' => false]);
            $mock->shouldReceive('confirmRegistration')->with('new-user', '123456')->once();
            $mock->shouldReceive('login')->once()->andReturn($result);
        });
        $this->get('/login?'.http_build_query($this->params()));
        $id = session('portal.authorization.request_id');
        $registration = $this->post('/register', ['portal_request' => $id, 'username' => 'new-user', 'email' => 'new@example.com', 'first_name' => 'New', 'last_name' => 'User', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'accept_policies' => 1])->assertRedirectContains('/register/confirm');
        $this->get($registration->headers->get('Location'))->assertOk()->assertSee('continue to Backstage');
        $confirmation = $this->post('/register/confirm', ['portal_request' => $id, 'username' => 'new-user', 'email' => 'new@example.com', 'code' => '123456'])->assertRedirectContains('/login');
        $this->get($confirmation->headers->get('Location'))->assertOk()->assertSee('continue to Backstage');
        $this->post('/login', ['portal_request' => $id, 'email' => 'new-user', 'password' => 'Password123!'])->assertRedirectContains('https://staging.rockschool.io/cognito-login?code=');
    }
}
