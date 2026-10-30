<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CognitoMigrationUsernameTest extends TestCase
{
    private function identity(array $overrides = []): array
    {
        return array_merge([
            'source' => 'cloud', 'id' => 'source-123', 'username' => 'person@example.com',
            'email' => 'person@example.com', 'given_name' => 'Aaron', 'family_name' => "O'Sullivan",
        ], $overrides);
    }

    private function directory(CognitoIdentityProviderClient $client): CognitoDirectory
    {
        config(['migration.enabled' => true, 'migration.password_recovery_enabled' => true, 'sso.management_writes_enabled' => true]);

        return Mockery::mock(CognitoDirectory::class, [$client])->makePartial();
    }

    private function expectCreated(CognitoIdentityProviderClient $client, string $username): void
    {
        $client->shouldReceive('adminCreateUser')->once()->with(Mockery::on(function ($input) use ($username) {
            $attributes = array_column($input['UserAttributes'], 'Value', 'Name');

            return $input['Username'] === $username && $input['ForceAliasCreation'] === false
                && $attributes['email'] === 'person@example.com' && $attributes['given_name'] === 'Aaron'
                && $attributes['family_name'] === "O'Sullivan";
        }))->andReturn(new Result);
        $client->shouldReceive('adminSetUserPassword')->once()->with(Mockery::on(fn ($input) => $input['Username'] === $username && $input['Permanent'] === true))->andReturn(new Result);
    }

    public function test_email_username_uses_given_and_family_names(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan')->andReturn(false);
        $this->expectCreated($client, 'aaron.osullivan');

        $this->assertSame('aaron.osullivan', $directory->provision($this->identity(), 'Password123!'));
    }

    public function test_wordpress_preferred_username_is_saved_with_the_canonical_username(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('wp_123')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $client->shouldReceive('adminCreateUser')->once()->with(Mockery::on(fn ($input) => $input['Username'] === 'wp_123'
            && array_column($input['UserAttributes'], 'Value', 'Name')['preferred_username'] === 'legacy-user'))->andReturn(new Result);
        $client->shouldReceive('adminSetUserPassword')->once()->with(Mockery::on(fn ($input) => $input['Username'] === 'wp_123'))->andReturn(new Result);

        $this->assertSame('wp_123', $directory->provision($this->identity(['source' => 'wordpress', 'username' => 'wp_123', 'preferred_username' => 'legacy-user']), 'Password123!'));
    }

    public function test_existing_name_candidates_get_an_unused_numeric_suffix(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan')->andReturn(true);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan_2')->andReturn(true);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan_3')->andReturn(false);
        $this->expectCreated($client, 'aaron.osullivan_3');

        $this->assertSame('aaron.osullivan_3', $directory->provision($this->identity(), 'Password123!'));
    }

    public function test_username_taken_between_check_and_creation_retries_without_modifying_that_account(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan_2')->andReturn(false);
        $client->shouldReceive('adminCreateUser')->once()->with(Mockery::on(fn ($input) => $input['Username'] === 'aaron.osullivan'))
            ->andThrow(new AwsException('Taken', new Command('AdminCreateUser'), ['code' => 'UsernameExistsException']));
        $this->expectCreated($client, 'aaron.osullivan_2');

        $this->assertSame('aaron.osullivan_2', $directory->provision($this->identity(), 'Password123!'));
    }

    public function test_existing_email_never_creates_another_account(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(true);
        $client->shouldNotReceive('adminCreateUser');
        $client->shouldNotReceive('adminSetUserPassword');
        $this->expectExceptionMessage('An account already exists.');

        $directory->provision($this->identity(), 'Password123!');
    }

    public function test_normal_legacy_username_is_preserved(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('legacy-user')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $this->expectCreated($client, 'legacy-user');

        $this->assertSame('legacy-user', $directory->provision($this->identity(['username' => 'legacy-user']), 'Password123!'));
    }

    public function test_password_recovery_uses_the_same_collision_handling(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan')->andReturn(true);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan_2')->andReturn(false);
        $this->expectCreated($client, 'aaron.osullivan_2');

        $this->assertSame('aaron.osullivan_2', $directory->provisionForPasswordRecovery($this->identity()));
    }

    public function test_failure_setting_password_after_creation_does_not_retry_creation(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan')->andReturn(false);
        $client->shouldReceive('adminCreateUser')->once()->andReturn(new Result);
        $client->shouldReceive('adminSetUserPassword')->once()->andThrow(new AwsException('Unavailable', new Command('AdminSetUserPassword'), ['code' => 'InternalErrorException']));
        $this->expectExceptionMessage('Account migration could not be completed.');

        $directory->provision($this->identity(), 'Password123!');
    }

    public function test_email_alias_conflict_is_not_retried_as_a_username_collision(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with('aaron.osullivan')->andReturn(false);
        $client->shouldReceive('adminCreateUser')->once()->andThrow(new AwsException('Alias taken', new Command('AdminCreateUser'), ['code' => 'AliasExistsException']));
        $client->shouldNotReceive('adminSetUserPassword');
        $this->expectException(RuntimeException::class);

        $directory->provision($this->identity(), 'Password123!');
    }

    public function test_long_names_leave_room_for_a_unique_suffix(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $directory = $this->directory($client);
        $base = str_repeat('a', 120);
        $directory->shouldReceive('hasIdentity')->once()->with('person@example.com')->andReturn(false);
        $directory->shouldReceive('hasIdentity')->once()->with($base)->andReturn(true);
        $directory->shouldReceive('hasIdentity')->once()->with($base.'_2')->andReturn(false);
        $client->shouldReceive('adminCreateUser')->once()->with(Mockery::on(fn ($input) => $input['Username'] === $base.'_2' && strlen($input['Username']) <= 128))->andReturn(new Result);
        $client->shouldReceive('adminSetUserPassword')->once()->with(Mockery::on(fn ($input) => $input['Username'] === $base.'_2'))->andReturn(new Result);

        $this->assertSame($base.'_2', $directory->provision($this->identity(['given_name' => str_repeat('a', 200), 'family_name' => str_repeat('b', 200)]), 'Password123!'));
    }
}
