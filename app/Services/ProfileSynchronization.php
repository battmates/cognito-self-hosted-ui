<?php

namespace App\Services;

use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProfileSynchronization
{
    // Extend this list alongside /profile validation when adding editable fields.
    private const PROFILE_ATTRIBUTES = ['given_name', 'family_name', 'name'];

    public function __construct(private readonly CognitoIdentityProviderClient $client, private readonly CognitoIdentityService $identity) {}

    public function updateAttributes(array $sessionUser, array $input): void
    {
        $input = Arr::only($input, self::PROFILE_ATTRIBUTES);
        if (isset($input['given_name'], $input['family_name'])) {
            $input['name'] = trim($input['given_name'].' '.$input['family_name']);
        }
        $targets = $this->targets($sessionUser, false);
        $attributes = [];
        foreach ($input as $name => $value) {
            if (is_string($value)) {
                $attributes[] = ['Name' => $name, 'Value' => $value];
            }
        }
        if ($attributes === []) {
            throw new RuntimeException('No editable profile fields were supplied.');
        }
        $completed = 0;
        try {
            foreach ($targets as $target) {
                $this->client->adminUpdateUserAttributes(['UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $target['Username'], 'UserAttributes' => $attributes]);
                $completed++;
            }
        } catch (AwsException $exception) {
            $this->logFailure('attributes', $exception, $completed, count($targets));
            throw new RuntimeException($completed > 0
                ? 'Some matching accounts were updated, but the profile update could not be completed. Please try saving again.'
                : 'Your profile could not be updated. Please try again.');
        }
    }

    public function changePassword(array $sessionUser, string $accessToken, string $currentPassword, string $newPassword): void
    {
        $targets = $this->targets($sessionUser, true);
        // The current account is last and is changed through its access token.
        array_pop($targets);
        // Verify the current password through Cognito before touching another account.
        $this->identity->changeOwnPassword($accessToken, $currentPassword, $newPassword);
        $completed = 1;
        try {
            foreach ($targets as $target) {
                $this->client->adminSetUserPassword(['UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $target['Username'], 'Password' => $newPassword, 'Permanent' => true]);
                $completed++;
            }
        } catch (AwsException $exception) {
            $this->logFailure('password', $exception, $completed, count($targets) + 1);
            throw new RuntimeException('Your password was changed on this account, but could not be updated on all matching native accounts. Please contact support.');
        }
    }

    private function targets(array $sessionUser, bool $nativeOnly): array
    {
        $username = $sessionUser['username'] ?? null;
        $subject = $sessionUser['subject'] ?? null;
        if (! is_string($username) || $username === '' || ! is_string($subject) || $subject === '') {
            throw new RuntimeException('Please sign in again before updating your profile.');
        }
        try {
            $source = $this->get($username);
            $attributes = $this->attributes($source);
            if (! ($source['Enabled'] ?? false) || ! in_array($source['UserStatus'] ?? '', ['CONFIRMED', 'EXTERNAL_PROVIDER'], true)
                || ! hash_equals($subject, (string) ($attributes['sub'] ?? ''))) {
                throw new RuntimeException('Your account could not be verified. Please sign in again.');
            }
            if ($nativeOnly && $this->isFederated($source)) {
                throw new RuntimeException('Sign in with your native Single Sign On username and password to change your password. Social sign-in passwords are managed by the social provider.');
            }
            $email = trim((string) ($attributes['email'] ?? ''));
            $matches = [];
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $cursor = null;
                do {
                    $params = ['UserPoolId' => config('services.cognito.user_pool_id'), 'Filter' => 'email = "'.addcslashes(mb_strtolower($email), '\\"').'"', 'Limit' => 60];
                    if ($cursor) {
                        $params['PaginationToken'] = $cursor;
                    }
                    $page = $this->client->listUsers($params);
                    foreach ($page['Users'] ?? [] as $candidate) {
                        if (($candidate['Username'] ?? '') === $source['Username']) {
                            continue;
                        }
                        if (mb_strtolower(trim((string) ($this->attributes($candidate)['email'] ?? ''))) !== mb_strtolower($email)) {
                            continue;
                        }
                        // Recheck current attributes rather than trusting a stale search result.
                        $current = $this->get($candidate['Username']);
                        $currentAttributes = $this->attributes($current);
                        if (mb_strtolower(trim((string) ($currentAttributes['email'] ?? ''))) !== mb_strtolower($email)
                            || ($currentAttributes['sub'] ?? null) !== ($this->attributes($candidate)['sub'] ?? null)) {
                            throw new RuntimeException('A matching account changed while your profile was being checked. Please try again.');
                        }
                        if (! $nativeOnly || ! $this->isFederated($current)) {
                            $matches[$current['Username']] = $current;
                        }
                    }
                    $cursor = $page['PaginationToken'] ?? null;
                } while ($cursor);
            }
            if ($matches !== [] && ($attributes['email_verified'] ?? '') !== 'true') {
                throw new RuntimeException('Verify your email address before updating other accounts that share it. Please contact support if you need help verifying your email.');
            }
            // Save the current account last for attributes, after its matching identities.
            $matches[$source['Username']] = $source;

            return array_values($matches);
        } catch (AwsException $exception) {
            $this->logFailure('lookup', $exception, 0, 0);
            throw new RuntimeException('Matching accounts could not be checked. No changes were made. Please try again.');
        }
    }

    private function get(string $username): array
    {
        return $this->client->adminGetUser(['UserPoolId' => config('services.cognito.user_pool_id'), 'Username' => $username])->toArray();
    }

    private function attributes(array $user): array
    {
        return array_column($user['UserAttributes'] ?? $user['Attributes'] ?? [], 'Value', 'Name');
    }

    private function isFederated(array $user): bool
    {
        if (($user['UserStatus'] ?? '') === 'EXTERNAL_PROVIDER') {
            return true;
        }
        $identities = json_decode($this->attributes($user)['identities'] ?? '[]', true);
        foreach (is_array($identities) ? $identities : [] as $identity) {
            if (isset($identity['providerName'], $identity['userId']) && strcasecmp($user['Username'], $identity['providerName'].'_'.$identity['userId']) === 0) {
                return true;
            }
        }

        return false;
    }

    private function logFailure(string $operation, AwsException $exception, int $completed, int $total): void
    {
        Log::warning('portal.profile_sync.failed', ['operation' => $operation, 'aws_error' => $exception->getAwsErrorCode(), 'completed' => $completed, 'total' => $total]);
    }
}
