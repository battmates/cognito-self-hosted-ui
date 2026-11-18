<?php

namespace Tests\Feature;

use App\Services\CognitoIdentityService;
use App\Services\ProfileSynchronization;
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    private function authSession(): array
    {
        return ['auth' => ['status' => ['authenticated' => true, 'user' => [
            'username' => 'portal-user', 'subject' => 'sub-portal-user', 'email' => 'portal@example.test', 'first_name' => 'Alex', 'last_name' => 'Example',
            'name' => 'Alex Example', 'user_role' => 'ops_manager',
        ]], 'tokens' => ['access_token' => 'access-token', 'expires_at' => time() + 3600]]];
    }

    private function account(string $username, string $status = 'CONFIRMED', string $email = 'portal@example.test', bool $verified = true): array
    {
        return ['Username' => $username, 'Enabled' => true, 'UserStatus' => $status, 'UserAttributes' => [
            ['Name' => 'sub', 'Value' => 'sub-'.$username], ['Name' => 'email', 'Value' => $email], ['Name' => 'email_verified', 'Value' => $verified ? 'true' : 'false'],
        ]];
    }

    private function directory(array $users = []): CognitoIdentityProviderClient
    {
        $users += ['portal-user' => $this->account('portal-user')];
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminGetUser')->andReturnUsing(fn ($params) => new Result($users[$params['Username']]));
        $client->shouldReceive('listUsers')->with(\Mockery::on(fn ($p) => $p['Filter'] === 'email = "portal@example.test"'))->andReturn(new Result(['Users' => array_values($users)]));

        return $client;
    }

    private function passwordInput(): array
    {
        return ['current_password' => 'CurrentPassword123!', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
    }

    public function test_profile_only_exposes_name_and_password_controls(): void
    {
        $this->withSession($this->authSession())->get('/profile')->assertOk()
            ->assertSee('Edit profile')->assertSee('First name')->assertSee('Last name')->assertSee('Change password')
            ->assertDontSee('Role<input', false)->assertDontSee('Email<input', false);
    }

    public function test_profile_name_update_only_sends_allowed_attributes(): void
    {
        $client = $this->directory();
        $client->shouldReceive('adminUpdateUserAttributes')->once()->with(\Mockery::on(fn ($p) => $p['Username'] === 'portal-user' && $p['UserAttributes'] === [
            ['Name' => 'given_name', 'Value' => 'Updated'], ['Name' => 'family_name', 'Value' => 'Person'], ['Name' => 'name', 'Value' => 'Updated Person'],
        ]))->andReturn(new Result);
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person', 'email' => 'attacker@example.test', 'custom:user_role' => 'administrator'])
            ->assertRedirect('/profile')->assertSessionHas('auth.status.user.name', 'Updated Person');
    }

    public function test_names_are_synced_to_native_and_social_identities_with_the_same_email(): void
    {
        $client = $this->directory(['native-other' => $this->account('native-other'), 'Google_123' => $this->account('Google_123', 'EXTERNAL_PROVIDER', 'PORTAL@example.test')]);
        $updated = [];
        $client->shouldReceive('adminUpdateUserAttributes')->times(3)->andReturnUsing(function ($p) use (&$updated) {
            $updated[] = $p['Username'];
            $this->assertSame(['given_name', 'family_name', 'name'], array_column($p['UserAttributes'], 'Name'));
            return new Result;
        });
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])->assertRedirect('/profile');
        $this->assertSame(['native-other', 'Google_123', 'portal-user'], $updated);
    }

    public function test_password_change_requires_current_password_and_uses_cognito_change_password(): void
    {
        $client = $this->directory();
        $client->shouldNotReceive('adminSetUserPassword');
        $this->withSession($this->authSession())->put('/profile/password', ['password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertSessionHasErrors('current_password');
        Http::fake(function ($request) {
            $this->assertSame('AWSCognitoIdentityProviderService.ChangePassword', $request->header('X-Amz-Target')[0]);
            $this->assertSame('CurrentPassword123!', $request['PreviousPassword']);
            $this->assertSame('NewPassword123!', $request['ProposedPassword']);
            return Http::response([]);
        });
        $this->withSession($this->authSession())->put('/profile/password', $this->passwordInput())->assertRedirect('/profile')->assertSessionHas('portal.notice', 'Password changed successfully.');
    }

    public function test_password_sync_skips_social_identities_even_if_their_status_was_changed_to_confirmed(): void
    {
        $converted = $this->account('Google_123');
        $converted['UserAttributes'][] = ['Name' => 'identities', 'Value' => json_encode([['providerName' => 'Google', 'userId' => '123']])];
        $client = $this->directory(['native-other' => $this->account('native-other'), 'Apple_123' => $this->account('Apple_123', 'EXTERNAL_PROVIDER'), 'Google_123' => $converted]);
        $verified = false;
        Http::fake(function () use (&$verified) { $verified = true; return Http::response([]); });
        $client->shouldReceive('adminSetUserPassword')->once()->with(\Mockery::on(function ($p) use (&$verified) {
            $this->assertTrue($verified);
            return $p['Username'] === 'native-other' && $p['Password'] === 'NewPassword123!' && $p['Permanent'];
        }))->andReturn(new Result);
        $this->withSession($this->authSession())->put('/profile/password', $this->passwordInput())->assertRedirect('/profile');
    }

    public function test_wrong_current_password_never_changes_other_accounts(): void
    {
        $client = $this->directory(['native-other' => $this->account('native-other')]);
        $client->shouldNotReceive('adminSetUserPassword');
        Http::fake(['*' => Http::response(['__type' => 'NotAuthorizedException'], 400)]);
        $this->withSession($this->authSession())->put('/profile/password', $this->passwordInput())->assertSessionHasErrors('password');
    }

    public function test_unverified_source_email_cannot_update_another_account_even_with_a_verified_session_claim(): void
    {
        $client = $this->directory(['portal-user' => $this->account('portal-user', 'CONFIRMED', 'portal@example.test', false), 'native-other' => $this->account('native-other')]);
        $client->shouldNotReceive('adminUpdateUserAttributes');
        $client->shouldNotReceive('adminSetUserPassword');
        Http::fake();
        $session = $this->authSession();$session['auth']['status']['user']['email_verified'] = true;
        $this->withSession($session)->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])->assertSessionHasErrors('profile');
        $this->withSession($session)->put('/profile/password', $this->passwordInput())->assertSessionHasErrors('password');
        Http::assertNothingSent();
    }

    public function test_an_unverified_user_can_still_update_their_own_profile_when_there_are_no_other_matches(): void
    {
        $client = $this->directory(['portal-user' => $this->account('portal-user', 'CONFIRMED', 'portal@example.test', false)]);
        $client->shouldReceive('adminUpdateUserAttributes')->once()->andReturn(new Result);
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])->assertRedirect('/profile');
    }

    public function test_a_social_session_does_not_enable_native_password_signin(): void
    {
        $client = $this->directory(['portal-user' => $this->account('portal-user', 'EXTERNAL_PROVIDER')]);
        $client->shouldNotReceive('adminSetUserPassword');Http::fake();
        $this->withSession($this->authSession())->put('/profile/password', $this->passwordInput())->assertSessionHasErrors('password');
        Http::assertNothingSent();
    }

    public function test_matching_email_lookup_follows_pagination_including_empty_pages(): void
    {
        $source = $this->account('portal-user');$other = $this->account('native-other');
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminGetUser')->andReturnUsing(fn ($p) => new Result($p['Username'] === 'portal-user' ? $source : $other));
        $client->shouldReceive('listUsers')->once()->with(\Mockery::on(fn ($p) => !isset($p['PaginationToken'])))->andReturn(new Result(['Users' => [], 'PaginationToken' => 'next']));
        $client->shouldReceive('listUsers')->once()->with(\Mockery::on(fn ($p) => $p['PaginationToken'] === 'next'))->andReturn(new Result(['Users' => [$other]]));
        $client->shouldReceive('adminUpdateUserAttributes')->twice()->andReturn(new Result);
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])->assertRedirect('/profile');
    }

    public function test_identity_or_email_changes_during_lookup_block_all_writes(): void
    {
        $other = $this->account('native-other');
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($p) => $p['Username'] === 'portal-user'))->andReturn(new Result($this->account('portal-user')));
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($p) => $p['Username'] === 'native-other'))->andReturn(new Result($this->account('native-other', 'CONFIRMED', 'changed@example.test')));
        $client->shouldReceive('listUsers')->andReturn(new Result(['Users' => [$other]]));$client->shouldNotReceive('adminUpdateUserAttributes');
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])->assertSessionHasErrors('profile');
    }

    public function test_session_subject_must_match_the_current_directory_identity(): void
    {
        $client = $this->directory();$client->shouldNotReceive('adminUpdateUserAttributes');
        $session = $this->authSession();$session['auth']['status']['user']['subject'] = 'different-sub';
        $this->withSession($session)->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])->assertSessionHasErrors('profile');
    }

    public function test_partial_password_failure_is_reported_without_claiming_success(): void
    {
        $client = $this->directory(['native-other' => $this->account('native-other')]);Http::fake(['*' => Http::response([])]);
        $client->shouldReceive('adminSetUserPassword')->once()->andThrow(new AwsException('failure',new Command('AdminSetUserPassword'),['code'=>'AccessDeniedException']));
        $this->withSession($this->authSession())->put('/profile/password', $this->passwordInput())
            ->assertSessionHasErrors(['password' => 'Your password was changed on this account, but could not be updated on all matching native accounts. Please contact support.'])->assertSessionMissing('portal.notice');
    }

    public function test_partial_name_failure_is_reported_and_does_not_change_the_session_name(): void
    {
        $client = $this->directory(['native-other' => $this->account('native-other')]);
        $client->shouldReceive('adminUpdateUserAttributes')->once()->with(\Mockery::on(fn ($p) => $p['Username'] === 'native-other'))->andReturn(new Result);
        $client->shouldReceive('adminUpdateUserAttributes')->once()->with(\Mockery::on(fn ($p) => $p['Username'] === 'portal-user'))->andThrow(new AwsException('failure', new Command('AdminUpdateUserAttributes'), ['code' => 'AccessDeniedException']));
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])
            ->assertSessionHasErrors('profile')->assertSessionHas('auth.status.user.name', 'Alex Example')->assertSessionMissing('portal.notice');
    }

    public function test_lookup_failure_blocks_changes_instead_of_updating_only_one_account(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminGetUser')->andReturn(new Result($this->account('portal-user')));
        $client->shouldReceive('listUsers')->andThrow(new AwsException('failure', new Command('ListUsers'), ['code' => 'AccessDeniedException']));
        $client->shouldNotReceive('adminUpdateUserAttributes');
        $this->withSession($this->authSession())->put('/profile', ['given_name' => 'Updated', 'family_name' => 'Person'])
            ->assertSessionHasErrors(['profile' => 'Matching accounts could not be checked. No changes were made. Please try again.']);
    }
}
