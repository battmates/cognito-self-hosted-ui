<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cognito.region' => 'eu-west-2', 'services.cognito.client_id' => 'test-client']);
        $this->mock(CognitoDirectory::class)->shouldReceive('migrationPasswordPolicy')->andReturn([
            'minimum_length' => 12, 'uppercase' => true, 'lowercase' => true, 'numbers' => true, 'symbols' => true,
        ]);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'email' => 'person@example.com', 'code' => '123456',
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ], $overrides);
    }

    private function assertRetained(array $input, string $errorField): void
    {
        $response = $this->post('/reset-password', $input);
        $response->assertStatus(422)->assertViewIs('portal.index')
            ->assertViewHas('errors', fn ($errors) => $errors->has($errorField))
            ->assertHeader('Cache-Control', 'no-store, private');
        foreach ($input as $value) {
            $response->assertSee('value="'.e($value).'"', false);
        }
        $response->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.code');
    }

    public function test_reset_page_shows_the_pool_password_requirements(): void
    {
        $this->get('/reset-password')->assertOk()->assertSee('Choose a strong password')
            ->assertSee('At least 12 characters')->assertSee('An uppercase letter')
            ->assertSee('A lowercase letter')->assertSee('A number')->assertSee('A symbol');
    }

    #[DataProvider('weakPasswords')]
    public function test_weak_password_keeps_every_field_without_calling_cognito(string $password): void
    {
        $this->assertRetained($this->input(['password' => $password, 'password_confirmation' => $password]), 'password');
        Http::assertNothingSent();
    }

    public static function weakPasswords(): array
    {
        return [['weak'], ['lowercase123!'], ['UPPERCASE123!'], ['WithoutNumbers!'], ['WithoutSymbols123']];
    }

    public function test_mismatched_confirmation_keeps_both_entered_passwords(): void
    {
        $this->assertRetained($this->input(['password_confirmation' => 'DifferentPassword123!']), 'password');
        Http::assertNothingSent();
    }

    public function test_cognito_policy_error_is_attached_to_password_and_keeps_all_fields(): void
    {
        Http::fake(['*' => Http::response(['__type' => 'InvalidPasswordException'], 400)]);
        $this->assertRetained($this->input(), 'password');
        Http::assertSentCount(1);
    }

    public function test_invalid_reset_code_keeps_all_fields(): void
    {
        Http::fake(['*' => Http::response(['__type' => 'CodeMismatchException'], 400)]);
        $this->assertRetained($this->input(), 'code');
    }

    public function test_successful_reset_still_redirects_to_sign_in(): void
    {
        Http::fake(['*' => Http::response([])]);
        $this->post('/reset-password', $this->input())->assertRedirect()
            ->assertSessionHas('portal.notice', 'Password updated successfully. Please sign in.');
        Http::assertSent(fn ($request) => $request->header('X-Amz-Target')[0] === 'AWSCognitoIdentityProviderService.ConfirmForgotPassword'
            && $request['Password'] === 'NewPassword123!' && $request['ConfirmationCode'] === '123456');
    }
}
