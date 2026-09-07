<?php

namespace App\Services;

use App\Exceptions\OAuthException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AuthorizationBroker
{
    public function capture(Request $request): ?array
    {
        if ($request->attributes->has('portal.captured')) {
            return $request->attributes->get('portal.captured');
        }
        if (! $request->hasAny(['client_id', 'redirect_uri', 'response_type'])) {
            $id = $request->input('portal_request');
            if ($id === 'direct' || $id === null) {
                $request->session()->forget('portal.authorization');

                return null;
            }
            if ($id !== null) {
                $journeys = $request->session()->get('portal.journeys', []);
                if (! is_string($id) || ! preg_match('/^[a-f0-9]{32}$/D', $id) || ! isset($journeys[$id])) {
                    throw new OAuthException('invalid_request', 'The sign-in request could not be found. Please return to the application.');
                }
                $request->session()->put('portal.authorization', $journeys[$id]);
            }
            $context = $request->session()->get('portal.authorization');
            if ($context && ($context['expires_at'] ?? 0) <= time()) {
                $request->session()->forget('portal.authorization');
                throw new OAuthException('invalid_request', 'Your sign-in request expired. Please return to the application and try again.');
            }
            if ($context) {
                $request->attributes->set('portal.captured', $context);
            }

            return $context;
        }

        $request->session()->forget('portal.authorization');
        $client = $request->input('client_id');
        $redirect = $request->input('redirect_uri');
        $this->assertClient($client);
        $consumer = $this->consumerFor($redirect, 'callback_urls');
        if (! $consumer) {
            throw new OAuthException('invalid_request', 'The callback URL is not registered for this application.');
        }
        if ($request->input('response_type') !== 'code') {
            throw new OAuthException('unsupported_response_type', 'Only authorization code sign-in is supported.');
        }
        $scope = $request->input('scope', 'openid email profile');
        if (! is_string($scope) || ! in_array('openid', explode(' ', $scope), true) || array_diff(explode(' ', $scope), ['openid', 'email', 'profile', 'phone'])) {
            throw new OAuthException('invalid_scope', 'Only openid, email, profile and phone identity scopes are supported.');
        }
        if ($request->hasAny(['nonce', 'resource', 'max_age'])) {
            throw new OAuthException('invalid_request', 'This broker does not support nonce, resource or max_age requests.');
        }
        $prompt = $request->input('prompt');
        if ($prompt !== null && ! in_array($prompt, ['login', 'none', 'forgot_password'], true)) {
            throw new OAuthException('invalid_request', 'Unsupported sign-in prompt.');
        }
        $state = $request->input('state');
        if ($state !== null && (! is_string($state) || strlen($state) > 4096)) {
            throw new OAuthException('invalid_request', 'Invalid state.');
        }
        $challenge = $request->input('code_challenge');
        if (($challenge !== null || $request->has('code_challenge_method')) && (! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) || $request->input('code_challenge_method') !== 'S256')) {
            throw new OAuthException('invalid_request', 'PKCE requires a valid S256 challenge.');
        }
        if (! config('services.cognito.client_secret') && ! $challenge) {
            throw new OAuthException('invalid_request', 'Public clients must use PKCE.');
        }
        $context = ['request_id' => bin2hex(random_bytes(16)), 'client_id' => $client, 'redirect_uri' => $redirect, 'consumer' => $consumer, 'state' => $state, 'scope' => $scope, 'code_challenge' => $challenge, 'prompt' => $prompt, 'expires_at' => time() + config('sso.state_ttl_seconds')];
        $journeys = array_filter($request->session()->get('portal.journeys', []), fn ($journey) => $journey['expires_at'] > time());
        if (count($journeys) >= 10) {
            array_shift($journeys);
        }
        $journeys[$context['request_id']] = $context;
        $request->session()->put('portal.journeys', $journeys);
        $request->session()->put('portal.authorization', $context);
        $request->attributes->set('portal.captured', $context);

        return $context;
    }

    public function issue(array $context, array $tokens): string
    {
        $this->assertClient($context['client_id'] ?? null);
        if (($context['expires_at'] ?? 0) <= time() || ! $this->consumerFor($context['redirect_uri'] ?? null, 'callback_urls')) {
            throw new OAuthException('invalid_request', 'The sign-in request expired or its callback is no longer registered.');
        }
        if (($tokens['expires_at'] ?? 0) <= time() + 10 || empty($tokens['id_token'])) {
            throw new OAuthException('login_required', 'Please sign in again.');
        }
        if (($tokens['client_id'] ?? null) !== $context['client_id']) {
            throw new OAuthException('login_required', 'Please sign in again for this application client.');
        }
        $tokens = array_intersect_key($tokens, array_flip(['id_token', 'access_token', 'expires_at']));
        $code = bin2hex(random_bytes(32));
        DB::table('portal_authorization_codes')->insert([
            'code_hash' => hash('sha256', $code),
            'payload' => Crypt::encryptString(json_encode(['authorization' => $context, 'tokens' => $tokens], JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addSeconds(config('sso.code_ttl_seconds')),
        ]);

        return $this->redirect($context, ['code' => $code]);
    }

    public function exchange(Request $request): array
    {
        $client = $request->input('client_id');
        $secret = $request->input('client_secret');
        if ($request->hasHeader('Authorization')) {
            $header = $request->header('Authorization');
            if (! preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/D', $header, $match) || $request->has('client_secret')) {
                throw new OAuthException('invalid_client', 'Invalid client authentication.', 401);
            }
            $decoded = base64_decode($match[1], true);
            if (! is_string($decoded) || ! str_contains($decoded, ':')) {
                throw new OAuthException('invalid_client', 'Invalid client authentication.', 401);
            }
            [$basicClient, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            if ($client !== null && $client !== $basicClient) {
                throw new OAuthException('invalid_client', 'Conflicting client identifiers.', 401);
            }
            $client = $basicClient;
        }
        $this->assertClient($client);
        $configuredSecret = (string) config('services.cognito.client_secret');
        if ($configuredSecret !== '' && (! is_string($secret) || ! hash_equals($configuredSecret, $secret))) {
            throw new OAuthException('invalid_client', 'Invalid client credentials.', 401);
        }
        if ($request->input('grant_type') !== 'authorization_code') {
            throw new OAuthException('unsupported_grant_type', 'Only authorization_code is supported.');
        }
        $code = $request->input('code');
        if (! is_string($code) || ! preg_match('/^[a-f0-9]{64}$/D', $code)) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid or expired.');
        }
        $hash = hash('sha256', $code);
        $row = DB::table('portal_authorization_codes')->where('code_hash', $hash)->where('expires_at', '>', now())->first();
        if (! $row) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid or expired.');
        }
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);
        $context = $payload['authorization'];
        if ($context['client_id'] !== $client || $context['redirect_uri'] !== $request->input('redirect_uri') || ! $this->consumerFor($context['redirect_uri'], 'callback_urls')) {
            throw new OAuthException('invalid_grant', 'The code does not belong to this client and callback.');
        }
        if ($context['code_challenge']) {
            $verifier = $request->input('code_verifier');
            if (! is_string($verifier) || ! preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || ! hash_equals($context['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
                throw new OAuthException('invalid_grant', 'PKCE verification failed.');
            }
        }
        $tokens = $payload['tokens'];
        if (($tokens['expires_at'] ?? 0) <= time()) {
            throw new OAuthException('invalid_grant', 'The sign-in expired.');
        }
        // A conditional delete grants exactly one concurrent request ownership.
        if (DB::table('portal_authorization_codes')->where('code_hash', $hash)->where('expires_at', '>', now())->delete() !== 1) {
            throw new OAuthException('invalid_grant', 'The authorization code has already been used.');
        }

        // The portal owns refresh tokens; downstream apps obtain a new code via SSO.
        return ['id_token' => $tokens['id_token'], 'access_token' => $tokens['access_token'], 'token_type' => 'Bearer', 'expires_in' => max(0, $tokens['expires_at'] - time())];
    }

    public function redirect(array $context, array $parameters): string
    {
        if ($context['state'] !== null) {
            $parameters['state'] = $context['state'];
        }

        return $context['redirect_uri'].(str_contains($context['redirect_uri'], '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function logoutUrl(Request $request): ?string
    {
        if (! $request->hasAny(['client_id', 'logout_uri'])) {
            return null;
        }
        $this->assertClient($request->input('client_id'));
        $url = $request->input('logout_uri');
        if (! $this->consumerFor($url, 'logout_urls')) {
            throw new OAuthException('invalid_request', 'The logout URL is not registered.');
        }

        return $url;
    }

    private function assertClient(mixed $client): void
    {
        $expected = config('services.cognito.client_id');
        if (! is_string($client) || ! is_string($expected) || $expected === '' || ! hash_equals($expected, $client)) {
            throw new OAuthException('invalid_client', 'Unknown application client.', 401);
        }
    }

    private function consumerFor(mixed $url, string $field): ?string
    {
        if (! is_string($url) || parse_url($url, PHP_URL_FRAGMENT) !== null || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && ! ($scheme === 'http' && in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true) && app()->environment(['local', 'testing']))) {
            return null;
        }
        foreach (config('sso.consumers', []) as $key => $consumer) {
            if (in_array($url, $consumer[$field] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }
}
