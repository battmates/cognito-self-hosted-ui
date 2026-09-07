<?php

namespace Tests\Feature;

use App\Services\CognitoIdentityService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CognitoIdentityTest extends TestCase
{
    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cognito.region' => 'eu-west-2', 'services.cognito.user_pool_id' => 'test-pool', 'services.cognito.client_id' => 'test-client', 'services.cognito.client_secret' => 'test-secret', 'services.cognito.domain' => 'auth.example.com']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        Cache::put('cognito.jwks.eu-west-2.test-pool', ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test-key', 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]]]);
    }

    private function token(array $overrides = []): string
    {
        return JWT::encode(array_merge(['iss' => 'https://cognito-idp.eu-west-2.amazonaws.com/test-pool', 'aud' => 'test-client', 'token_use' => 'id', 'sub' => 'subject-1', 'cognito:username' => 'canonical-user', 'email' => 'test@example.com', 'custom:user_role' => 'administrator', 'iat' => time(), 'exp' => time() + 3600], $overrides), $this->privateKey, 'RS256', 'test-key');
    }

    public function test_password_auth_validates_token_and_uses_canonical_username_for_refresh(): void
    {
        Http::fake(['*' => Http::response(['AuthenticationResult' => ['IdToken' => $this->token(), 'AccessToken' => 'access', 'RefreshToken' => 'refresh', 'ExpiresIn' => 3600]])]);
        $identity = app(CognitoIdentityService::class);
        $result = $identity->login('test@example.com', 'Password123!');
        $this->assertSame('canonical-user', $result['tokens']['refresh_username']);
        $this->assertTrue($result['user']['is_admin']);
        $identity->refresh($result['tokens']);
        Http::assertSent(fn ($request) => $request['AuthFlow'] === 'REFRESH_TOKEN_AUTH' && $request['AuthParameters']['SECRET_HASH'] === base64_encode(hash_hmac('sha256', 'canonical-user'.'test-client', 'test-secret', true)));
    }

    #[DataProvider('invalidClaims')]
    public function test_wrong_token_claims_are_rejected(array $claims): void
    {
        Http::fake(['*' => Http::response(['AuthenticationResult' => ['IdToken' => $this->token($claims), 'AccessToken' => 'access', 'ExpiresIn' => 3600]])]);
        $this->expectException(\Exception::class);
        app(CognitoIdentityService::class)->login('user', 'password');
    }

    public static function invalidClaims(): array
    {
        return [[['aud' => 'other-client']], [['iss' => 'https://attacker.example']], [['token_use' => 'access']], [['exp' => 1]]];
    }

    public function test_mfa_challenge_is_completed_without_storing_password(): void
    {
        Http::fakeSequence()->push(['ChallengeName' => 'SOFTWARE_TOKEN_MFA', 'Session' => 'challenge-session', 'ChallengeParameters' => ['USERNAME' => 'canonical-user']])->push(['AuthenticationResult' => ['IdToken' => $this->token(), 'AccessToken' => 'access', 'ExpiresIn' => 3600]]);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'Password123!', 'portal_request' => 'direct'])->assertRedirect(route('portal.challenge', ['portal_request' => 'direct']))->assertSessionMissing('auth.status')->assertSessionMissing('_old_input.password');
        $this->get('/challenge')->assertOk()->assertSee('authenticator app');
        $this->post('/challenge', ['code' => '123456', 'portal_request' => 'direct'])->assertRedirect(route('portal.home'))->assertSessionHas('auth.status.authenticated', true)->assertSessionMissing('portal.challenge');
        Http::assertSent(fn ($request) => $request->header('X-Amz-Target')[0] === 'AWSCognitoIdentityProviderService.RespondToAuthChallenge' && $request['ChallengeResponses']['SOFTWARE_TOKEN_MFA_CODE'] === '123456');
    }

    public function test_social_redirect_normalizes_domain_and_uses_pkce_and_nonce(): void
    {
        $result = app(CognitoIdentityService::class)->buildSocialLoginUrl('google');
        parse_str(parse_url($result['url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://auth.example.com/oauth2/authorize?', $result['url']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($result['nonce'], $query['nonce']);
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $result['verifier'], true)), $query['code_challenge']);
    }

    public function test_social_callback_requires_session_bound_unexpired_state_before_token_exchange(): void
    {
        $this->withSession(['portal.social_auth' => ['state' => 'expected', 'expires_at' => time() + 300]])->get('/callback?state=wrong&code=code')->assertRedirect()->assertSessionHas('portal.error')->assertSessionMissing('portal.social_auth');
        Http::assertNothingSent();
    }

    public function test_social_exchange_rejects_nonce_mismatch(): void
    {
        Http::fake(['*' => Http::response(['id_token' => $this->token(['nonce' => 'wrong']), 'access_token' => 'access', 'expires_in' => 3600])]);
        $this->expectExceptionMessage('social sign-in response could not be verified');
        app(CognitoIdentityService::class)->exchangeAuthorizationCode('code', [], ['nonce' => 'expected', 'verifier' => 'verifier']);
    }

    public function test_failed_logout_still_invalidates_local_session(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->withSession(['auth' => ['status' => ['authenticated' => true, 'user' => []], 'tokens' => ['access_token' => 'access', 'expires_at' => time() + 3600]]])->post('/logout')->assertRedirect(route('portal.home'))->assertSessionMissing('auth');
    }

    public function test_valid_social_callback_creates_portal_session_and_cannot_be_replayed(): void
    {
        $this->get('/login/google?portal_request=direct')->assertRedirect();
        $social = session('portal.social_auth');
        Http::fake(['*' => Http::response(['id_token' => $this->token(['nonce' => $social['nonce']]), 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600])]);
        $this->get('/callback?'.http_build_query(['state' => $social['state'], 'code' => 'social-code']))->assertRedirect(route('portal.home'))->assertSessionHas('auth.status.authenticated', true)->assertSessionMissing('portal.social_auth');
        $this->get('/callback?'.http_build_query(['state' => $social['state'], 'code' => 'social-code']))->assertRedirect()->assertSessionHas('portal.error');
        Http::assertSentCount(1);
    }

    public function test_temporary_password_challenge_requires_and_sends_new_account_details(): void
    {
        Http::fakeSequence()->push(['ChallengeName' => 'NEW_PASSWORD_REQUIRED', 'Session' => 'challenge-session', 'ChallengeParameters' => ['USERNAME' => 'canonical-user', 'requiredAttributes' => '["userAttributes.given_name","userAttributes.family_name"]']])->push(['AuthenticationResult' => ['IdToken' => $this->token(), 'AccessToken' => 'access', 'ExpiresIn' => 3600]]);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'Temporary123!', 'portal_request' => 'direct'])->assertRedirect();
        $this->get('/challenge?portal_request=direct')->assertOk()->assertSee('Choose your password');
        $this->post('/challenge', ['portal_request' => 'direct', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!', 'given_name' => 'Example', 'family_name' => 'User'])->assertRedirect(route('portal.home'))->assertSessionHas('auth.status.authenticated', true);
        Http::assertSent(fn ($request) => ($request['ChallengeResponses']['NEW_PASSWORD'] ?? null) === 'NewPassword123!' && $request['ChallengeResponses']['userAttributes.given_name'] === 'Example');
    }
}
