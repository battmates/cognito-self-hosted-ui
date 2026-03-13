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
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign in directly on this app', false);
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
            'email' => 'user@example.com',
            'password' => 'wrong-password',
            'consumer' => 'wordpress_backstage',
            'redirect_to' => 'https://backstage.example.com/cognito-login',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'Incorrect email or password.',
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

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret',
            'consumer' => 'wordpress_backstage',
            'redirect_to' => 'https://backstage.example.com/cognito-login',
        ]);

        $response->assertRedirect(route('portal.home'));
        $response->assertSessionHas('portal.error');
    }
}
