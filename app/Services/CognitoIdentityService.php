<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CognitoIdentityService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function login(string $username, string $password, array $context = []): array
    {
        $payload = $this->call('InitiateAuth', [
            'AuthFlow' => env('COGNITO_AUTH_FLOW', 'USER_PASSWORD_AUTH'),
            'ClientId' => config('services.cognito.client_id'),
            'AuthParameters' => array_filter([
                'USERNAME' => $username,
                'PASSWORD' => $password,
                'SECRET_HASH' => $this->secretHash($username),
            ]),
        ]);

        if (! empty($payload['ChallengeName'])) {
            throw new RuntimeException(sprintf('Cognito challenge "%s" is not implemented yet.', $payload['ChallengeName']));
        }

        $result = $payload['AuthenticationResult'] ?? null;
        if (! is_array($result) || empty($result['IdToken'])) {
            throw new RuntimeException('Cognito did not return a usable sign-in response.');
        }

        $tokens = [
            'id_token' => $result['IdToken'],
            'access_token' => $result['AccessToken'] ?? null,
            'refresh_token' => $result['RefreshToken'] ?? null,
            'expires_in' => $result['ExpiresIn'] ?? null,
            'token_type' => $result['TokenType'] ?? null,
        ];

        $claims = $this->validateIdToken($tokens['id_token']);

        return [
            'tokens' => $tokens,
            'user' => $this->buildSessionUser($claims, $context),
        ];
    }

    public function register(array $input): array
    {
        $attributes = array_values(array_filter([
            ['Name' => 'email', 'Value' => $input['email']],
            ! empty($input['first_name']) ? ['Name' => 'given_name', 'Value' => $input['first_name']] : null,
            ! empty($input['last_name']) ? ['Name' => 'family_name', 'Value' => $input['last_name']] : null,
        ]));

        $payload = $this->call('SignUp', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $input['email'],
            'Password' => $input['password'],
            'SecretHash' => $this->secretHash($input['email']),
            'UserAttributes' => $attributes,
        ]);

        return [
            'username' => $payload['UserSub'] ?? $input['email'],
            'email' => $input['email'],
            'confirmed' => (bool) ($payload['UserConfirmed'] ?? false),
        ];
    }

    public function confirmRegistration(string $email, string $code): void
    {
        $this->call('ConfirmSignUp', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $email,
            'ConfirmationCode' => $code,
            'SecretHash' => $this->secretHash($email),
        ]);
    }

    public function resendConfirmation(string $email): void
    {
        $this->call('ResendConfirmationCode', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $email,
            'SecretHash' => $this->secretHash($email),
        ]);
    }

    public function startForgotPassword(string $email): void
    {
        $this->call('ForgotPassword', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $email,
            'SecretHash' => $this->secretHash($email),
        ]);
    }

    public function confirmForgotPassword(string $email, string $code, string $password): void
    {
        $this->call('ConfirmForgotPassword', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $email,
            'ConfirmationCode' => $code,
            'Password' => $password,
            'SecretHash' => $this->secretHash($email),
        ]);
    }

    public function logout(?string $accessToken): void
    {
        if (! is_string($accessToken) || $accessToken === '') {
            return;
        }

        try {
            $this->call('GlobalSignOut', [
                'AccessToken' => $accessToken,
            ]);
        } catch (RuntimeException) {
            // Local logout should still succeed if Cognito global sign-out fails.
        }
    }

    public function buildSessionUser(array $claims, array $context = []): array
    {
        $claimMap = config('sso.claim_map', []);
        $roles = [];

        foreach ($claimMap['roles'] ?? [] as $roleClaim) {
            $value = Arr::get($claims, $roleClaim);

            if (is_array($value)) {
                $roles = [...$roles, ...$value];
            } elseif (is_string($value) && $value !== '') {
                $roles[] = $value;
            }
        }

        $firstName = Arr::get($claims, $claimMap['first_name'] ?? 'given_name');
        $lastName = Arr::get($claims, $claimMap['last_name'] ?? 'family_name');

        return [
            'subject' => Arr::get($claims, $claimMap['subject'] ?? 'sub'),
            'email' => Arr::get($claims, $claimMap['email'] ?? 'email'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim(implode(' ', array_filter([$firstName, $lastName]))) ?: Arr::get($claims, 'name'),
            'roles' => array_values(array_unique($roles)),
            'consumer' => $context['consumer'] ?? null,
            'origin' => $context['origin'] ?? null,
            'redirect_to' => $context['redirect_to'] ?? null,
            'signed_in_at' => now()->toIso8601String(),
        ];
    }

    public function buildReturnUrl(array $context, array $user): ?string
    {
        $redirectTo = $context['redirect_to'] ?? null;
        if (! is_string($redirectTo) || $redirectTo === '') {
            return null;
        }

        $consumer = $context['consumer'] ?? null;
        $allowedHosts = config("sso.consumers.{$consumer}.allowed_return_hosts", []);
        $baseUrlHost = parse_url((string) config("sso.consumers.{$consumer}.base_url"), PHP_URL_HOST);
        if (is_string($baseUrlHost) && $baseUrlHost !== '' && ! in_array($baseUrlHost, $allowedHosts, true)) {
            $allowedHosts[] = $baseUrlHost;
        }
        $host = parse_url($redirectTo, PHP_URL_HOST);

        if (! is_string($host) || ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException(sprintf(
                'Return URL host "%s" is not allowed for consumer "%s". Add it to the consumer allowed hosts config.',
                is_string($host) ? $host : 'unknown',
                is_string($consumer) ? $consumer : 'unknown'
            ));
        }

        $separator = str_contains($redirectTo, '?') ? '&' : '?';

        return $redirectTo.$separator.http_build_query([
            'sso_token' => $this->buildHandoffToken($user, $context),
            'sso_source' => config('app.url'),
        ]);
    }

    private function call(string $target, array $payload): array
    {
        $response = $this->http
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/x-amz-json-1.1',
                'X-Amz-Target' => sprintf('AWSCognitoIdentityProviderService.%s', $target),
            ])
            ->post(sprintf('https://cognito-idp.%s.amazonaws.com/', config('services.cognito.region')), $payload);

        if ($response->failed()) {
            $errorType = (string) $response->header('x-amzn-errortype', '');
            $message = $response->json('message') ?: 'Cognito request failed.';

            throw new RuntimeException($this->mapErrorMessage($errorType, is_string($message) ? $message : 'Cognito request failed.'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function mapErrorMessage(string $errorType, string $fallback): string
    {
        return match (true) {
            str_contains($errorType, 'NotAuthorizedException') => 'Incorrect email or password.',
            str_contains($errorType, 'UsernameExistsException') => 'An account with this email already exists.',
            str_contains($errorType, 'UserNotConfirmedException') => 'This account is not confirmed yet.',
            str_contains($errorType, 'CodeMismatchException') => 'The confirmation code is invalid.',
            str_contains($errorType, 'ExpiredCodeException') => 'The confirmation code has expired.',
            str_contains($errorType, 'InvalidPasswordException') => 'The password does not meet the Cognito policy.',
            str_contains($errorType, 'LimitExceededException') => 'Too many attempts. Try again later.',
            str_contains($errorType, 'UserNotFoundException') => 'No account was found for that email.',
            default => $fallback,
        };
    }

    private function validateIdToken(string $idToken): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new RuntimeException('Invalid Cognito ID token.');
        }

        $headers = json_decode(JWT::urlsafeB64Decode($segments[0]), true);
        $kid = $headers['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw new RuntimeException('Missing Cognito signing key.');
        }

        $keys = JWK::parseKeySet($this->getJwks());
        $key = $keys[$kid] ?? null;

        if (! $key instanceof Key) {
            throw new RuntimeException('Unable to match Cognito signing key.');
        }

        $claims = (array) JWT::decode($idToken, $key);
        $issuer = sprintf(
            'https://cognito-idp.%s.amazonaws.com/%s',
            config('services.cognito.region'),
            config('services.cognito.user_pool_id'),
        );

        if (($claims['token_use'] ?? null) !== 'id') {
            throw new RuntimeException('Unexpected token_use in Cognito ID token.');
        }

        if (($claims['aud'] ?? null) !== config('services.cognito.client_id')) {
            throw new RuntimeException('Cognito audience mismatch.');
        }

        if (($claims['iss'] ?? null) !== $issuer) {
            throw new RuntimeException('Cognito issuer mismatch.');
        }

        return $claims;
    }

    private function getJwks(): array
    {
        return Cache::remember('cognito.jwks', now()->addHours(6), function (): array {
            $payload = $this->http
                ->acceptJson()
                ->get(sprintf(
                    'https://cognito-idp.%s.amazonaws.com/%s/.well-known/jwks.json',
                    config('services.cognito.region'),
                    config('services.cognito.user_pool_id'),
                ))
                ->throw()
                ->json();

            if (! is_array($payload) || ! isset($payload['keys'])) {
                throw new RuntimeException('Unable to load Cognito JWKS.');
            }

            return $payload;
        });
    }

    private function buildHandoffToken(array $user, array $context): string
    {
        $secret = (string) (config('app.key') ?: config('app.name'));

        return JWT::encode([
            'iss' => config('app.url'),
            'aud' => $context['consumer'] ?? 'direct',
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
            'user' => $user,
        ], $secret, 'HS256');
    }

    private function secretHash(string $username): ?string
    {
        $clientSecret = config('services.cognito.client_secret');
        $clientId = config('services.cognito.client_id');

        if (! is_string($clientSecret) || $clientSecret === '' || ! is_string($clientId) || $clientId === '') {
            return null;
        }

        return base64_encode(hash_hmac('sha256', $username.$clientId, $clientSecret, true));
    }
}
