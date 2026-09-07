<?php

namespace App\Services;

use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Exception\AwsException;
use RuntimeException;

class CognitoDirectory
{
    public function __construct(private readonly CognitoIdentityProviderClient $client) {}

    public function get(string $username): ?array
    {
        try {
            return $this->client->adminGetUser(['UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $username])->toArray();
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'UserNotFoundException') {
                return null;
            }
            throw new RuntimeException('The user directory is unavailable. Please try again.');
        }
    }

    public function search(string $field = 'email', string $query = '', ?string $cursor = null, bool $exact = false): array
    {
        if (! in_array($field, ['email', 'username', 'given_name', 'family_name', 'sub'], true)) {
            throw new RuntimeException('Unsupported search field.');
        }
        $input = ['UserPoolId' => config('services.cognito.user_pool_id'), 'Limit' => 30];
        if ($query !== '') {
            $input['Filter'] = $field.($exact ? ' = ' : ' ^= ').'"'.addcslashes($query, '\\"').'"';
        }
        if ($cursor) {
            $input['PaginationToken'] = $cursor;
        }
        try {
            if ($field === 'email' && $query !== '' && ! $exact) {
                return $this->searchEmailContains($query, $cursor);
            }

            return $this->client->listUsers($input)->toArray();
        } catch (AwsException) {
            throw new RuntimeException('The user search could not be completed. Please try again.');
        }
    }

    private function searchEmailContains(string $query, ?string $cursor): array
    {
        $users = [];
        // Cognito only supports exact/prefix filters. Scan bounded batches for
        // substring matches, preserving the source cursor without skipping users.
        for ($batch = 0; $batch < 10; $batch++) {
            $input = ['UserPoolId' => config('services.cognito.user_pool_id'), 'Limit' => 30 - count($users)];
            if ($cursor) {
                $input['PaginationToken'] = $cursor;
            }
            $page = $this->client->listUsers($input)->toArray();
            foreach ($page['Users'] ?? [] as $user) {
                $email = $this->attributes($user)['email'] ?? '';
                if (mb_stripos($email, $query) !== false) {
                    $users[] = $user;
                }
            }
            $cursor = $page['PaginationToken'] ?? null;
            if (! $cursor || count($users) >= 30) {
                break;
            }
        }

        return ['Users' => $users, 'PaginationToken' => $cursor];
    }

    public function attributes(array $user): array
    {
        return array_column($user['UserAttributes'] ?? $user['Attributes'] ?? [], 'Value', 'Name');
    }

    public function isAdministrator(array $user): bool
    {
        if (! ($user['Enabled'] ?? false) || ! in_array($user['UserStatus'] ?? '', ['CONFIRMED', 'EXTERNAL_PROVIDER'], true)) {
            return false;
        }
        $attributes = $this->attributes($user);
        foreach (config('sso.admin_role_attributes') as $name) {
            if (in_array(strtolower(trim($attributes[$name] ?? '')), config('sso.management_roles'), true)) {
                return true;
            }
        }

        return false;
    }

    public function hasIdentity(string $identifier): bool
    {
        if ($this->get($identifier)) {
            return true;
        }
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $page = $this->search('email', strtolower($identifier), null, true);

            return ! empty($page['Users']) || ! empty($page['PaginationToken']);
        }

        return false;
    }

    public function manage(string $username, string $action, array $input = []): void
    {
        if (! config('sso.management_writes_enabled')) {
            throw new RuntimeException('User management is currently read-only. Writes must be enabled by the portal operator.');
        }
        $operation = match ($action) {
            'confirm' => 'adminConfirmSignUp', 'enable' => 'adminEnableUser', 'disable' => 'adminDisableUser',
            'reset_password' => 'adminResetUserPassword', 'sign_out' => 'adminUserGlobalSignOut', 'set_password' => 'adminSetUserPassword',
            'delete' => 'adminDeleteUser',
            default => throw new RuntimeException('Unsupported user management action.'),
        };
        if ($action === 'delete') {
            $target = $this->get($username);
            if (! $target) {
                throw new RuntimeException('This user no longer exists.');
            }
            $actor = $input['actor_username'] ?? '';
            if (! is_string($actor) || $actor === '' || strcasecmp($target['Username'], $actor) === 0) {
                throw new RuntimeException('You cannot delete your own account.');
            }
            $subject = $this->attributes($target)['sub'] ?? '';
            if ($target['Username'] !== $username || ($input['delete_confirmation'] ?? null) !== $username || $subject === '' || ($input['user_subject'] ?? null) !== $subject) {
                throw new RuntimeException('The account or deletion confirmation has changed. Reopen the profile and type its exact username to confirm.');
            }
        }
        $parameters = ['UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $username];
        if ($action === 'set_password') {
            $parameters['Password'] = $input['password'];
            $parameters['Permanent'] = ! ($input['temporary'] ?? false);
        }
        try {
            $this->client->$operation($parameters);
        } catch (AwsException $e) {
            throw new RuntimeException(match ($e->getAwsErrorCode()) {
                'InvalidPasswordException' => 'The password does not meet the pool password policy.',
                'UserNotFoundException' => 'This user no longer exists.',
                'AccessDeniedException' => 'The portal’s AWS credentials do not allow this action.',
                'NotAuthorizedException' => 'This action is not available for the user’s current status.',
                default => 'The user update could not be completed. Refresh the user and try again.',
            });
        }
    }

    public function provision(array $identity, string $password): string
    {
        if (! config('migration.enabled') || ! config('sso.management_writes_enabled')) {
            throw new RuntimeException('Migration writes are disabled.');
        }
        // Never adopt an existing account, even after credentials were verified elsewhere.
        if ($this->hasIdentity($identity['username']) || $this->hasIdentity($identity['email'])) {
            throw new RuntimeException('An account already exists. Sign in to it or contact support.');
        }
        try {
            $this->client->adminCreateUser([
                'UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $identity['username'],
                'TemporaryPassword' => $password, 'MessageAction' => 'SUPPRESS', 'ForceAliasCreation' => false,
                'UserAttributes' => [
                    ['Name' => 'email', 'Value' => $identity['email']],
                    ['Name' => 'email_verified', 'Value' => 'true'],
                    ['Name' => 'given_name', 'Value' => $identity['given_name']],
                    ['Name' => 'family_name', 'Value' => $identity['family_name']],
                ],
            ]);
            $this->client->adminSetUserPassword([
                'UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $identity['username'],
                'Password' => $password, 'Permanent' => true,
            ]);
        } catch (AwsException) {
            // Creation may have succeeded before password setting failed. Do not overwrite
            // or delete a possibly existing account; operator reconciliation is required.
            throw new RuntimeException('Account migration could not be completed. Please contact support.');
        }

        return $identity['username'];
    }
}
