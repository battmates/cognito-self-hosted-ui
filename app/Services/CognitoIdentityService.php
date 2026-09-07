<?php

namespace App\Services;

use App\Exceptions\CognitoException;
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
    ) {}

    public function login(string $username, string $password, array $context = []): array
    {
        if (config('migration.enabled')) {
            $username = app(LegacyMigrationService::class)->resolve($username, $password);
        }
        $payload = $this->call('InitiateAuth', [
            'AuthFlow' => config('services.cognito.auth_flow', 'USER_PASSWORD_AUTH'),
            'ClientId' => config('services.cognito.client_id'),
            'AuthParameters' => array_filter([
                'USERNAME' => $username,
                'PASSWORD' => $password,
                'SECRET_HASH' => $this->secretHash($username),
            ]),
        ]);

        return $this->authenticationResult($payload, $context, $username);
    }

    public function respondToChallenge(array $challenge, array $input, array $context): array
    {
        $name = $challenge['name'];
        $field = match ($name) {
            'SMS_MFA' => 'SMS_MFA_CODE',
            'SOFTWARE_TOKEN_MFA' => 'SOFTWARE_TOKEN_MFA_CODE',
            'EMAIL_OTP' => 'EMAIL_OTP_CODE',
            'NEW_PASSWORD_REQUIRED' => 'NEW_PASSWORD',
            default => throw new RuntimeException('This account needs an authentication step that is not yet supported. Please contact support.'),
        };
        $responses = array_filter(['USERNAME' => $challenge['username'], 'SECRET_HASH' => $this->secretHash($challenge['username'])]);
        $responses[$field] = $name === 'NEW_PASSWORD_REQUIRED' ? $input['password'] : $input['code'];
        if ($name === 'NEW_PASSWORD_REQUIRED') {
            foreach ($challenge['required_attributes'] ?? [] as $attribute) {
                if (! in_array($attribute, ['given_name', 'family_name', 'email'], true) || empty($input[$attribute])) {
                    throw new RuntimeException('Please complete all required account details.');
                }
                $responses['userAttributes.'.$attribute] = $input[$attribute];
            }
        }

        return $this->authenticationResult($this->call('RespondToAuthChallenge', [
            'ClientId' => config('services.cognito.client_id'), 'ChallengeName' => $name,
            'Session' => $challenge['session'], 'ChallengeResponses' => $responses,
        ]), $context, $challenge['username']);
    }

    private function authenticationResult(array $payload, array $context, string $username): array
    {
        if (! empty($payload['ChallengeName'])) {
            if (! in_array($payload['ChallengeName'], ['SMS_MFA', 'SOFTWARE_TOKEN_MFA', 'EMAIL_OTP', 'NEW_PASSWORD_REQUIRED'], true)) {
                throw new RuntimeException('This account needs an authentication step that is not yet supported. Please contact support.');
            }
            $required = json_decode($payload['ChallengeParameters']['requiredAttributes'] ?? '[]', true) ?: [];

            return ['challenge' => [
                'name' => $payload['ChallengeName'], 'session' => $payload['Session'],
                'username' => $payload['ChallengeParameters']['USER_ID_FOR_SRP'] ?? $payload['ChallengeParameters']['USERNAME'] ?? $username,
                'required_attributes' => array_map(fn ($value) => str_replace('userAttributes.', '', $value), $required),
                'expires_at' => time() + 180,
            ]];
        }
        $result = $payload['AuthenticationResult'] ?? [];

        return $this->sessionResult([
            'id_token' => $result['IdToken'] ?? '', 'access_token' => $result['AccessToken'] ?? '',
            'refresh_token' => $result['RefreshToken'] ?? null, 'expires_in' => $result['ExpiresIn'] ?? 0,
            'token_type' => $result['TokenType'] ?? 'Bearer',
        ], $context);
    }

    private function sessionResult(array $tokens, array $context): array
    {
        $claims = $this->validateIdToken($tokens['id_token']);
        if (empty($tokens['access_token']) || empty($claims['exp']) || empty($claims['sub'])) {
            throw new RuntimeException('Cognito did not return a usable sign-in response.');
        }
        $tokens['expires_at'] = min((int) $claims['exp'], time() + (int) $tokens['expires_in']);
        $tokens['client_id'] = $claims['aud'];
        $tokens['refresh_username'] = $claims['cognito:username'] ?? $claims['sub'];

        return ['tokens' => $tokens, 'user' => $this->buildSessionUser($claims, $context)];
    }

    public function refresh(array $tokens): array
    {
        if (empty($tokens['refresh_token']) || empty($tokens['refresh_username'])) {
            throw new RuntimeException('Please sign in again.');
        }
        $payload = $this->call('InitiateAuth', [
            'AuthFlow' => 'REFRESH_TOKEN_AUTH', 'ClientId' => config('services.cognito.client_id'),
            'AuthParameters' => array_filter([
                'REFRESH_TOKEN' => $tokens['refresh_token'],
                'SECRET_HASH' => $this->secretHash($tokens['refresh_username']),
            ]),
        ]);
        $result = $this->authenticationResult($payload, [], $tokens['refresh_username']);
        $result['tokens']['refresh_token'] = $result['tokens']['refresh_token'] ?? $tokens['refresh_token'];

        return $result;
    }

    public function socialProviders(): array
    {
        $providers = config('services.cognito.social_providers', []);

        return array_values(array_filter($providers, function ($provider): bool {
            return is_array($provider)
                && ($provider['enabled'] ?? false)
                && is_string($provider['slug'] ?? null)
                && is_string($provider['label'] ?? null)
                && is_string($provider['identity_provider'] ?? null);
        }));
    }

    public function buildSocialLoginUrl(string $providerSlug, array $context = []): array
    {
        $provider = collect($this->socialProviders())
            ->first(fn (array $candidate) => $candidate['slug'] === $providerSlug);

        if (! is_array($provider)) {
            throw new RuntimeException('That social sign-in provider is not available.');
        }

        $domain = $this->domain();
        $clientId = (string) config('services.cognito.client_id');
        $redirectUri = (string) config('services.cognito.redirect_uri');

        if ($domain === '' || $clientId === '' || $redirectUri === '') {
            throw new RuntimeException('Cognito social sign-in is not configured correctly.');
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));

        $url = $domain.'/oauth2/authorize?'.http_build_query([
            'identity_provider' => $provider['identity_provider'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'client_id' => $clientId,
            'scope' => implode(' ', config('services.cognito.scopes', ['openid', 'email', 'profile'])),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return [
            'provider' => $provider['label'],
            'nonce' => $nonce,
            'verifier' => $verifier,
            'state' => $state,
            'url' => $url,
        ];
    }

    public function exchangeAuthorizationCode(string $code, array $context = [], array $social = []): array
    {
        $domain = $this->domain();
        $clientId = (string) config('services.cognito.client_id');
        $redirectUri = (string) config('services.cognito.redirect_uri');

        if ($domain === '' || $clientId === '' || $redirectUri === '') {
            throw new RuntimeException('Cognito social sign-in is not configured correctly.');
        }

        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $social['verifier'] ?? '',
        ];

        $clientSecret = config('services.cognito.client_secret');
        if (is_string($clientSecret) && $clientSecret !== '') {
            $payload['client_secret'] = $clientSecret;
        }

        $response = $this->http
            ->timeout(15)->connectTimeout(5)
            ->asForm()
            ->acceptJson()
            ->post($domain.'/oauth2/token', $payload);

        if ($response->failed()) {
            $message = $response->json('error_description')
                ?: $response->json('error')
                ?: 'Cognito token exchange failed.';

            throw new RuntimeException(is_string($message) ? $message : 'Cognito token exchange failed.');
        }

        $result = $response->json();
        if (! is_array($result) || empty($result['id_token'])) {
            throw new RuntimeException('Cognito did not return a usable social sign-in response.');
        }

        $tokens = [
            'id_token' => $result['id_token'],
            'access_token' => $result['access_token'] ?? null,
            'refresh_token' => $result['refresh_token'] ?? null,
            'expires_in' => $result['expires_in'] ?? null,
            'token_type' => $result['token_type'] ?? null,
        ];

        $claims = $this->validateIdToken($tokens['id_token']);
        if (empty($social['nonce']) || ! hash_equals($social['nonce'], (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('The social sign-in response could not be verified.');
        }

        return $this->sessionResult($tokens, $context);
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
            'Username' => $input['username'],
            'Password' => $input['password'],
            'SecretHash' => $this->secretHash($input['username']),
            'UserAttributes' => $attributes,
        ]);

        return [
            'username' => $input['username'],
            'email' => $input['email'],
            'confirmed' => (bool) ($payload['UserConfirmed'] ?? false),
        ];
    }

    public function confirmRegistration(string $username, string $code): void
    {
        $this->call('ConfirmSignUp', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $username,
            'ConfirmationCode' => $code,
            'SecretHash' => $this->secretHash($username),
        ]);
    }

    public function resendConfirmation(string $username): void
    {
        $this->call('ResendConfirmationCode', [
            'ClientId' => config('services.cognito.client_id'),
            'Username' => $username,
            'SecretHash' => $this->secretHash($username),
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
        } catch (\Throwable) {
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
            'username' => $claims['cognito:username'] ?? null,
            'subject' => Arr::get($claims, $claimMap['subject'] ?? 'sub'),
            'email' => Arr::get($claims, $claimMap['email'] ?? 'email'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim(implode(' ', array_filter([$firstName, $lastName]))) ?: Arr::get($claims, 'name'),
            'user_role' => Arr::get($claims, 'custom:user_role'),
            'roles' => array_values(array_unique($roles)),
            'is_admin' => collect(config('sso.admin_role_attributes'))->contains(fn ($name) => in_array(strtolower(trim((string) ($claims[$name] ?? ''))), config('sso.management_roles'), true)),
            'consumer' => $context['consumer'] ?? null,
            'origin' => $context['origin'] ?? null,
            'redirect_to' => $context['redirect_to'] ?? null,
            'signed_in_at' => now()->toIso8601String(),
        ];
    }

    private function call(string $target, array $payload): array
    {
        $response = $this->http
            ->timeout(15)->connectTimeout(5)
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/x-amz-json-1.1',
                'X-Amz-Target' => sprintf('AWSCognitoIdentityProviderService.%s', $target),
            ])
            ->post(sprintf('https://cognito-idp.%s.amazonaws.com/', config('services.cognito.region')), $payload);

        if ($response->failed()) {
            $errorType = (string) ($response->header('x-amzn-errortype') ?: $response->json('__type') ?: '');
            $message = $response->json('message') ?: 'Cognito request failed.';

            throw new CognitoException($errorType, $this->mapErrorMessage($errorType, 'Sign-in service is unavailable. Please try again.'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function mapErrorMessage(string $errorType, string $fallback): string
    {
        return match (true) {
            str_contains($errorType, 'NotAuthorizedException') => 'Incorrect email, username, or password.',
            str_contains($errorType, 'UsernameExistsException') => 'An account with this email already exists.',
            str_contains($errorType, 'UserNotConfirmedException') => 'This account is not confirmed yet.',
            str_contains($errorType, 'CodeMismatchException') => 'The confirmation code is invalid.',
            str_contains($errorType, 'ExpiredCodeException') => 'The confirmation code has expired.',
            str_contains($errorType, 'InvalidPasswordException') => 'The password does not meet the Cognito policy.',
            str_contains($errorType, 'LimitExceededException') => 'Too many attempts. Try again later.',
            str_contains($errorType, 'UserNotFoundException') => 'No account was found for that email or username.',
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
        if (! isset($keys[$kid])) {
            Cache::forget($this->jwksCacheKey());
            $keys = JWK::parseKeySet($this->getJwks());
        }
        $key = $keys[$kid] ?? null;

        if (! $key instanceof Key) {
            throw new RuntimeException('Unable to match Cognito signing key.');
        }

        try {
            $claims = (array) JWT::decode($idToken, $key);
        } catch (\Throwable) {
            throw new RuntimeException('The sign-in token could not be verified. Please sign in again.');
        }
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
        return Cache::remember($this->jwksCacheKey(), now()->addHours(6), function (): array {
            $payload = $this->http
                ->timeout(15)->connectTimeout(5)
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

    private function jwksCacheKey(): string
    {
        return 'cognito.jwks.'.config('services.cognito.region').'.'.config('services.cognito.user_pool_id');
    }

    private function domain(): string
    {
        $domain = rtrim((string) config('services.cognito.domain'), '/');

        return $domain === '' ? '' : 'https://'.preg_replace('#^https?://#', '', $domain);
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
