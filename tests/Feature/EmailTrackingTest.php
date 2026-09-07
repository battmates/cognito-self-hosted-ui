<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use App\Services\SesMetrics;
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class EmailTrackingTest extends TestCase
{
    private function managementSession(): array
    {
        return ['auth' => ['status' => ['authenticated' => true, 'user' => ['username' => 'operator', 'is_admin' => true]],
            'tokens' => ['expires_at' => time() + 3600]]];
    }

    private function allowManagementAccess(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('operator')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(true);
        });
    }

    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        $this->get('/admin/email-tracking')->assertRedirect(route('portal.login'));
    }

    public function test_email_tracking_requires_current_management_role(): void
    {
        $this->mock(CognitoDirectory::class, function ($mock) {
            $mock->shouldReceive('get')->with('operator')->andReturn(['Enabled' => true]);
            $mock->shouldReceive('isAdministrator')->andReturn(false);
        });
        $this->withSession($this->managementSession())->get('/admin/email-tracking')->assertForbidden();
    }

    public function test_tracking_page_renders_live_charts_and_no_exported_message_table(): void
    {
        $this->allowManagementAccess();
        $this->mock(SesMetrics::class, function ($mock) {
            $mock->shouldReceive('report')->once()->with(\Mockery::type(CarbonImmutable::class), \Mockery::type(CarbonImmutable::class))->andReturn([
                'days' => ['2026-09-01'],
                'volume' => [['name' => 'Sent', 'data' => [10]]],
                'rates' => [['name' => 'Sent', 'data' => [100]]],
                'source' => 'SES Virtual Deliverability Manager',
                'scope' => 'Configuration set: SES-Config-Set',
                'notices' => [],
                'available' => true,
                'updated_at' => '2026-09-07T12:00:00Z',
            ]);
        });
        $this->withSession($this->managementSession())->get('/admin/email-tracking?days=7')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Email tracking')->assertSee('Volume over time')->assertSee('Rate over time')
            ->assertSee('Message activity')->assertSee('does not show exported snapshots')
            ->assertDontSee('<table', false);
    }

    public function test_tracking_range_is_restricted_to_supported_values(): void
    {
        $this->allowManagementAccess();
        $this->mock(SesMetrics::class)->shouldNotReceive('report');
        $this->withSession($this->managementSession())->get('/admin/email-tracking?days=365')->assertSessionHasErrors('days');
    }

    public function test_ops_manager_role_grants_management_access(): void
    {
        $directory = new CognitoDirectory($this->mock(CognitoIdentityProviderClient::class));
        $user = ['Enabled' => true, 'UserStatus' => 'CONFIRMED', 'UserAttributes' => [
            ['Name' => 'custom:user_role', 'Value' => 'ops_manager'],
        ]];
        $this->assertTrue($directory->isAdministrator($user));
    }
}
