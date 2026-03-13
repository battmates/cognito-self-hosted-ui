<?php

namespace Tests\Feature;

use App\Services\CognitoIdentityService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Sign In', false);
        $response->assertSee('Continue with Google', false);
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Email or username', false);
        $response->assertSee('Forgot password', false);
    }

    public function test_login_shows_cognito_error_message(): void
    {
        Config::set('services.cognito.region', 'eu-west-2');
        Config::set('services.cognito.client_id', 'client-123');
        Config::set('services.cognito.client_secret', 'secret-123');
        Config::set('sso.consumers.wordpress_backstage.allowed_return_hosts', ['backstage.example.com']);

        Http::fake([
            'https://cognito-idp.eu-west-2.amazonaws.com/' => Http::response([
                'message' => 'Incorrect username or password.',
            ], 400, [
                'x-amzn-errortype' => 'NotAuthorizedException:',
            ]),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'backstage-user',
            'password' => 'wrong-password',
            'consumer' => 'wordpress_backstage',
            'redirect_to' => 'https://backstage.example.com/cognito-login',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'Incorrect email, username, or password.',
        ]);
    }

    public function test_login_redirect_validation_failure_returns_to_portal(): void
    {
        $mock = $this->mock(CognitoIdentityService::class);
        $mock->shouldReceive('login')->once()->andReturn([
            'tokens' => ['access_token' => 'abc'],
            'user' => ['email' => 'user@example.com'],
        ]);
        $mock->shouldReceive('buildReturnUrl')->once()->andThrow(
            new \RuntimeException('Return URL host "backstage.example.com" is not allowed for consumer "wordpress_backstage". Add it to the consumer allowed hosts config.')
        );
        $mock->shouldReceive('socialProviders')->andReturn([]);

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret',
            'consumer' => 'wordpress_backstage',
            'redirect_to' => 'https://backstage.example.com/cognito-login',
        ]);

        $response->assertRedirect(route('portal.home'));
        $response->assertSessionHas('portal.error');
    }

    public function test_social_provider_redirect_uses_cognito_authorize_endpoint(): void
    {
        Config::set('services.cognito.domain', 'https://auth.example.com');
        Config::set('services.cognito.client_id', 'client-123');
        Config::set('services.cognito.redirect_uri', 'http://localhost:8000/auth/callback');
        Config::set('services.cognito.scopes', ['openid', 'email', 'profile']);

        $response = $this->get('/login/google?consumer=wordpress_backstage&redirect_to=https://backstage.example.com/cognito-login');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringContainsString('https://auth.example.com/oauth2/authorize', $location);
        $this->assertStringContainsString('identity_provider=Google', $location);
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A8000%2Fauth%2Fcallback', $location);
    }

    public function test_registration_requires_policy_acceptance(): void
    {
        $response = $this->from('/register')->post('/register', [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'first_name' => 'New',
            'last_name' => 'User',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'accept_policies',
        ]);
    }
}
