<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    private function adminSession(): array
    {
        return ['auth' => ['status' => ['authenticated' => true, 'user' => ['username' => 'admin-user', 'is_admin' => true]], 'tokens' => ['expires_at' => time() + 3600]]];
    }

    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        $this->get('/admin/users')->assertRedirect(route('portal.login'));
    }

    public function test_stale_admin_claim_cannot_authorize_directory_access(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->once()->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->once()->andReturn(false);
            $mock->shouldNotReceive('search');
        });
        $this->withSession($this->adminSession())->get('/admin/users')->assertForbidden();
    }

    public function test_directory_outage_fails_closed(): void
    {
        $this->mock(CognitoDirectory::class)->shouldReceive('get')->andThrow(new \RuntimeException('unavailable'));
        $this->withSession($this->adminSession())->get('/admin/users')->assertStatus(503);
    }

    public function test_profile_shows_attributes_without_loading_the_list_and_preserves_search_filters(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $user = ['Username' => 'target', 'Enabled' => true, 'UserStatus' => 'CONFIRMED', 'Attributes' => [['Name' => 'email', 'Value' => 'target@example.com']]];
            $mock->shouldNotReceive('search');
            $mock->shouldReceive('get')->with('target')->andReturn($user);
            $mock->shouldReceive('attributes')->andReturn(['given_name' => 'Alex', 'family_name' => 'Example', 'custom:test' => '<script>alert(1)</script>']);
        });
        $this->withSession($this->adminSession())->get('/admin/users?field=email&q=target&username=target')
            ->assertOk()->assertViewIs('portal.admin-user')->assertSee('Read-only mode')->assertSee('CONFIRMED')
            ->assertSee('Alex Example')->assertSee('User name: target')->assertSee('Back to users')->assertSee('href="'.e(route('portal.admin.users', ['field' => 'email', 'q' => 'target'])).'"', false)
            ->assertDontSee('admin-search-form')->assertDontSee('<table', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('@hidden');
    }

    public function test_read_only_switch_blocks_sdk_mutations_even_outside_controller(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldNotReceive('adminSetUserPassword');
        $this->expectExceptionMessage('User management is currently read-only');
        (new CognitoDirectory($client))->manage('target', 'set_password', ['password' => 'ExamplePassword123!']);
    }

    public function test_directory_admin_roles_are_exact_and_disabled_users_are_denied(): void
    {
        $directory = new CognitoDirectory($this->mock(CognitoIdentityProviderClient::class));
        $user = ['Enabled' => true, 'UserStatus' => 'CONFIRMED', 'UserAttributes' => [['Name' => 'custom:user_role', 'Value' => 'administrator']]];
        $this->assertTrue($directory->isAdministrator($user));
        $user['Enabled'] = false;
        $this->assertFalse($directory->isAdministrator($user));
        $user['Enabled'] = true;
        $user['UserAttributes'][0]['Value'] = 'not-administrator';
        $this->assertFalse($directory->isAdministrator($user));
    }

    public function test_admin_action_requires_confirmation_and_self_disable_is_blocked(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $mock->shouldNotReceive('manage');
        });
        $this->withSession($this->adminSession())->post('/admin/users', ['username' => 'target', 'action' => 'disable'])->assertSessionHasErrors('confirm_action');
        $this->withSession($this->adminSession())->post('/admin/users', ['username' => 'admin-user', 'action' => 'disable', 'confirm_action' => 1])->assertSessionHasErrors('action');
    }

    public function test_approved_password_action_uses_only_the_expected_sdk_operation(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminSetUserPassword')->once()->with(\Mockery::on(fn ($input) => $input['Username'] === 'target' && $input['Password'] === 'TestPassword123!' && $input['Permanent'] === false));
        (new CognitoDirectory($client))->manage('target', 'set_password', ['password' => 'TestPassword123!', 'temporary' => true]);
    }

    public function test_live_search_returns_filtered_rows_and_load_more_without_the_page_layout(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->once()->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->once()->andReturn(true);
            $mock->shouldReceive('search')->with('username', 'target', null)->once()->andReturn([
                'Users' => [['Username' => 'target-user', 'Enabled' => true, 'UserStatus' => 'CONFIRMED', 'Attributes' => [['Name' => 'email', 'Value' => 'target@example.com']]]],
                'PaginationToken' => 'next-page',
            ]);
            $mock->shouldNotReceive('manage');
        });
        $response = $this->withSession($this->adminSession())->getJson('/admin/users?field=username&q=target')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('has_more', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('target-user', $response->json('html'));
        $this->assertStringContainsString('data-cursor="next-page"', $response->json('html'));
        $this->assertStringContainsString('Load more', $response->json('html'));
        $response->assertJsonPath('cursor', 'next-page');
        $this->assertStringContainsString('data-user-row="target-user"', $response->json('rows'));
        $this->assertStringContainsString('field=username&amp;q=target&amp;username=target-user', $response->json('rows'));
        $this->assertStringNotContainsString('Next page', $response->json('html'));
        $this->assertStringNotContainsString('<html', $response->json('html'));
        $this->assertStringNotContainsString('Manage account', $response->json('html'));
    }

    public function test_clearing_the_live_search_returns_an_unfiltered_page(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $mock->shouldReceive('search')->with('email', '', null)->once()->andReturn(['Users' => []]);
        });
        $this->withSession($this->adminSession())->getJson('/admin/users?field=email&q=')->assertOk()->assertJsonPath('count', 0)->assertJsonPath('has_more', false);
    }

    public function test_live_search_returns_json_when_the_session_expires_or_directory_fails(): void
    {
        $this->getJson('/admin/users?q=target')->assertUnauthorized()->assertJsonPath('message', 'Your session expired. Please sign in again.');
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $mock->shouldReceive('search')->andThrow(new \RuntimeException('Directory unavailable.'));
        });
        $this->withSession($this->adminSession())->getJson('/admin/users?q=target')->assertStatus(503)->assertJsonPath('message', 'Directory unavailable.');
    }

    public function test_non_admins_cannot_search_or_write_when_management_is_enabled(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(false);
            $mock->shouldNotReceive('search');
            $mock->shouldNotReceive('manage');
        });
        $this->withSession($this->adminSession())->getJson('/admin/users?q=target')->assertForbidden();
        $this->withSession($this->adminSession())->post('/admin/users', ['username' => 'target', 'action' => 'enable', 'confirm_action' => 1])->assertForbidden();
        $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput())->assertForbidden();
    }

    public function test_enabled_management_renders_active_controls_and_applies_authorized_changes(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'admin-user'))->andReturn(new Result([
            'Username' => 'admin-user', 'Enabled' => true, 'UserStatus' => 'CONFIRMED',
            'UserAttributes' => [['Name' => 'custom:user_role', 'Value' => 'administrator']],
        ]));
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'target'))->andReturn(new Result(['Username' => 'target', 'Enabled' => false, 'UserStatus' => 'CONFIRMED']));
        $client->shouldNotReceive('listUsers');
        $client->shouldReceive('adminEnableUser')->once()->with(\Mockery::on(fn ($input) => $input['Username'] === 'target'))->andReturn(new Result);
        $this->withSession($this->adminSession())->get('/admin/users?username=target')->assertOk()->assertDontSee('Read-only mode')->assertDontSee('<fieldset disabled', false)->assertSee('Apply action')->assertSee('Delete user')->assertSee('Type <strong>target</strong> to confirm', false);
        $this->withSession($this->adminSession())->post('/admin/users', ['username' => 'target', 'action' => 'enable', 'confirm_action' => 1, 'field' => 'username', 'q' => 'tar'])->assertRedirect(route('portal.admin.users', ['field' => 'username', 'q' => 'tar', 'username' => 'target']))->assertSessionHas('portal.notice', 'User updated successfully.');
    }

    public function test_load_more_uses_the_same_filters_and_returns_only_the_next_batch(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $mock->shouldReceive('search')->with('username', 'target', 'opaque-next-batch')->once()->andReturn([
                'Users' => [['Username' => 'target-two', 'Enabled' => true, 'UserStatus' => 'CONFIRMED']],
            ]);
        });
        $response = $this->withSession($this->adminSession())->getJson('/admin/users?field=username&q=target&cursor=opaque-next-batch')
            ->assertOk()->assertJsonPath('cursor', null)->assertJsonPath('count', 1)->assertJsonPath('has_more', false);
        $this->assertStringContainsString('data-user-row="target-two"', $response->json('rows'));
        $this->assertStringNotContainsString('cursor=', $response->json('rows'));
        $this->assertStringNotContainsString('data-load-more', $response->json('html'));
    }

    public function test_empty_batch_keeps_load_more_when_the_directory_has_another_cursor(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $mock->shouldReceive('search')->with('email', '', null)->andReturn(['Users' => [], 'PaginationToken' => 'next']);
        });
        $this->withSession($this->adminSession())->get('/admin/users')->assertOk()->assertSee('Load more')->assertDontSee('Next page');
        $this->withSession($this->adminSession())->getJson('/admin/users')->assertOk()->assertJsonPath('count', 0)->assertJsonPath('has_more', true)->assertJsonPath('cursor', 'next');
    }

    public function test_missing_profile_returns_not_found_without_listing_users(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('admin-user')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
            $mock->shouldReceive('get')->with('missing')->andReturn(null);
            $mock->shouldNotReceive('search');
        });
        $this->withSession($this->adminSession())->get('/admin/users?username=missing')->assertNotFound();
    }

    private function deleteInput(array $overrides = []): array
    {
        return array_replace([
            'username' => 'target', 'action' => 'delete', 'confirm_action' => 1,
            'delete_confirmation' => 'target', 'user_subject' => '123e4567-e89b-42d3-a456-426614174000',
        ], $overrides);
    }

    private function adminClient(): CognitoIdentityProviderClient
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'admin-user'))->andReturn(new Result([
            'Username' => 'admin-user', 'Enabled' => true, 'UserStatus' => 'CONFIRMED',
            'UserAttributes' => [['Name' => 'custom:user_role', 'Value' => 'administrator']],
        ]));

        return $client;
    }

    public function test_delete_requires_exact_username_subject_and_action_confirmation(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->adminClient();
        $client->shouldNotReceive('adminDeleteUser');
        foreach ([['delete_confirmation' => null], ['delete_confirmation' => 'TARGET'], ['user_subject' => null], ['confirm_action' => 0]] as $invalid) {
            $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput($invalid))->assertSessionHasErrors(array_key_first($invalid));
        }
    }

    public function test_confirmed_delete_calls_sdk_once_and_returns_to_the_filtered_list(): void
    {
        config(['sso.management_writes_enabled' => true]);
        Log::spy();
        $client = $this->adminClient();
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'target'))->once()->andReturn(new Result([
            'Username' => 'target', 'UserAttributes' => [['Name' => 'sub', 'Value' => $this->deleteInput()['user_subject']]],
        ]));
        $client->shouldReceive('adminDeleteUser')->once()->with(['UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => 'target'])->andReturn(new Result);
        $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput(['field' => 'username', 'q' => 'tar', 'cursor' => 'old-batch', 'actor_username' => 'forged']))
            ->assertRedirect(route('portal.admin.users', ['field' => 'username', 'q' => 'tar']))->assertSessionHas('portal.notice', 'User deleted successfully.');
        Log::shouldHaveReceived('notice')->with('portal.user_management', ['actor' => 'admin-user', 'target' => 'target', 'action' => 'delete', 'outcome' => 'succeeded'])->once();
    }

    public function test_delete_cannot_target_the_signed_in_admin_even_via_an_alias(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->adminClient();
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'admin@example.com'))->andReturn(new Result(['Username' => 'ADMIN-USER']));
        $client->shouldNotReceive('adminDeleteUser');
        $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput(['username' => 'admin-user', 'delete_confirmation' => 'admin-user']))->assertSessionHasErrors('action');
        $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput(['username' => 'admin@example.com', 'delete_confirmation' => 'admin@example.com']))->assertSessionHasErrors(['action' => 'You cannot delete your own account.']);
        $this->withSession($this->adminSession())->get('/admin/users?username=admin-user')->assertOk()->assertSee('You cannot delete your own account.');
    }

    public function test_delete_rejects_an_account_recreated_since_its_profile_was_opened(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->adminClient();
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'target'))->andReturn(new Result([
            'Username' => 'target', 'UserAttributes' => [['Name' => 'sub', 'Value' => '123e4567-e89b-42d3-a456-426614174001']],
        ]));
        $client->shouldNotReceive('adminDeleteUser');
        $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput())->assertSessionHasErrors('action')->assertSessionMissing('portal.notice');
    }

    public function test_read_only_switch_blocks_deletion_in_the_service(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldNotReceive('adminDeleteUser');
        $this->expectExceptionMessage('User management is currently read-only');
        (new CognitoDirectory($client))->manage('target', 'delete', $this->deleteInput(['actor_username' => 'admin-user']));
    }

    public function test_failed_sdk_deletion_reports_an_error_without_success_notice(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->adminClient();
        $client->shouldReceive('adminGetUser')->with(\Mockery::on(fn ($input) => $input['Username'] === 'target'))->andReturn(new Result([
            'Username' => 'target', 'UserAttributes' => [['Name' => 'sub', 'Value' => $this->deleteInput()['user_subject']]],
        ]));
        $client->shouldReceive('adminDeleteUser')->once()->andThrow(new AwsException('Denied', new Command('AdminDeleteUser'), ['code' => 'AccessDeniedException']));
        $this->withSession($this->adminSession())->post('/admin/users', $this->deleteInput())->assertSessionHasErrors('action')->assertSessionMissing('portal.notice');
    }

    public function test_confirmed_row_action_returns_to_the_filtered_list(): void
    {
        config(['sso.management_writes_enabled' => true]);
        $client = $this->adminClient();
        $client->shouldReceive('adminDisableUser')->once()->with(\Mockery::on(fn ($input) => $input['Username'] === 'target'))->andReturn(new Result);
        $this->withSession($this->adminSession())->post('/admin/users', [
            'username' => 'target', 'action' => 'disable', 'confirm_action' => 1,
            'return_to' => 'list', 'field' => 'email', 'q' => '@domain.com',
        ])->assertRedirect(route('portal.admin.users', ['field' => 'email', 'q' => '@domain.com']))->assertSessionHas('portal.notice', 'User updated successfully.');
    }

    public function test_row_actions_cannot_supply_an_arbitrary_return_destination(): void
    {
        $client = $this->adminClient();
        $client->shouldNotReceive('adminDisableUser');
        $this->withSession($this->adminSession())->post('/admin/users', [
            'username' => 'target', 'action' => 'disable', 'confirm_action' => 1, 'return_to' => 'https://other.example',
        ])->assertSessionHasErrors('return_to');
    }
}
