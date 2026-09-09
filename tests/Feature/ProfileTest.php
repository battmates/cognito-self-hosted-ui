<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    private function authSession(): array
    {
        return ['auth' => ['status' => ['authenticated' => true, 'user' => [
            'username' => 'portal-user', 'email' => 'portal@example.test', 'first_name' => 'Alex', 'last_name' => 'Example',
            'name' => 'Alex Example', 'user_role' => 'ops_manager',
        ]], 'tokens' => ['access_token' => 'access-token', 'expires_at' => time() + 3600]]];
    }

    public function test_profile_only_exposes_name_and_password_controls(): void
    {
        $this->withSession($this->authSession())->get('/profile')->assertOk()
            ->assertSee('Edit profile')->assertSee('First name')->assertSee('Last name')->assertSee('Change password')
            ->assertDontSee('Role<input', false)->assertDontSee('Email<input', false)
            ->assertSee('Edit profile', false);
    }

    public function test_profile_name_update_only_sends_allowed_attributes(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('AWSCognitoIdentityProviderService.UpdateUserAttributes', $request->header('X-Amz-Target')[0]);
            $this->assertSame([
                ['Name' => 'given_name', 'Value' => 'Updated'],
                ['Name' => 'family_name', 'Value' => 'Person'],
            ], $request->data()['UserAttributes']);

            return Http::response([]);
        });

        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])
            ->assertRedirect('/profile')->assertSessionHas('auth.status.user.name', 'Updated Person');
    }

    public function test_password_change_requires_current_password_and_uses_cognito_change_password(): void
    {
        $this->withSession($this->authSession())->put('/profile/password', ['password' => 'Password123!', 'password_confirmation' => 'Password123!'])
            ->assertSessionHasErrors('current_password');

        Http::fake(function ($request) {
            $this->assertSame('AWSCognitoIdentityProviderService.ChangePassword', $request->header('X-Amz-Target')[0]);
            $this->assertSame('CurrentPassword123!', $request->data()['PreviousPassword']);
            $this->assertSame('NewPassword123!', $request->data()['ProposedPassword']);

            return Http::response([]);
        });

        $this->withSession($this->authSession())->put('/profile/password', [
            'current_password' => 'CurrentPassword123!', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect('/profile')->assertSessionHas('portal.notice', 'Password changed successfully.');
    }
}
