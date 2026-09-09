<?php

namespace App\Http\Controllers;

use App\Services\AuthorizationBroker;
use App\Services\CognitoIdentityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthPortalController extends Controller
{
    public function __construct(
        private readonly CognitoIdentityService $identity,
        private readonly AuthorizationBroker $broker,
    ) {}

    public function home(Request $request): View|RedirectResponse
    {
        // A direct visit starts a new portal journey, without a stale app redirect.
        if (! $request->hasAny(['client_id', 'redirect_uri', 'response_type'])) {
            $request->session()->forget(['portal.authorization', 'portal.context']);
        }

        return $this->entry($request, 'login');
    }

    public function login(Request $request): View|RedirectResponse
    {
        return $this->entry($request, 'login');
    }

    public function register(Request $request): View|RedirectResponse
    {
        return $this->entry($request, 'register');
    }

    private function entry(Request $request, string $page): View|RedirectResponse
    {
        $context = $this->capturePortalContext($request);
        $authorization = $request->session()->get('portal.authorization');
        if (($authorization['prompt'] ?? null) === 'forgot_password') {
            $authorization['prompt'] = null;
            $request->session()->put('portal.authorization', $authorization);
            $request->session()->put('portal.journeys.'.$authorization['request_id'], $authorization);
            $request->attributes->set('portal.captured', $authorization);

            return $this->renderPage($request, 'forgot-password');
        }
        if ($request->session()->get('auth.status.authenticated') && ($authorization['prompt'] ?? null) !== 'login') {
            return $authorization ? $this->completeLogin($request) : $this->renderPage($request, 'status');
        }
        if (($authorization['prompt'] ?? null) === 'none') {
            $request->session()->forget('portal.authorization');

            return redirect()->away($this->broker->redirect($authorization, ['error' => 'login_required']));
        }

        return $this->renderPage($request, $page);
    }

    public function privacyPolicy(Request $request): View
    {
        return $this->renderPolicyPage(
            $request,
            'Privacy Policy',
            'Placeholder copy for the privacy policy. This will be replaced with managed policy content later.'
        );
    }

    public function termsAndConditions(Request $request): View
    {
        return $this->renderPolicyPage(
            $request,
            'Terms and Conditions',
            'Placeholder copy for the terms and conditions. This will be replaced with managed policy content later.'
        );
    }

    public function confirmRegistration(Request $request): View
    {
        return $this->renderPage($request, 'confirm-registration');
    }

    public function forgotPassword(Request $request): View
    {
        return $this->renderPage($request, 'forgot-password');
    }

    public function resetPassword(Request $request): View
    {
        return $this->renderPage($request, 'reset-password');
    }

    public function storeLogin(Request $request): RedirectResponse
    {
        $context = $this->capturePortalContext($request);
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:256'],
        ]);

        try {
            $result = $this->identity->login($validated['email'], $validated['password'], $context);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        return $this->acceptAuthentication($request, $result);
    }

    private function acceptAuthentication(Request $request, array $result): RedirectResponse
    {
        if (isset($result['challenge'])) {
            $request->session()->forget(['auth.status', 'auth.tokens']);
            $result['challenge']['authorization'] = $request->session()->get('portal.authorization');
            $request->session()->put('portal.challenge', $result['challenge']);

            return redirect()->route('portal.challenge', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct')]);
        }
        $request->session()->forget('portal.challenge');
        $request->session()->regenerate();
        $request->session()->put('auth.status', ['authenticated' => true, 'user' => $result['user']]);
        $request->session()->put('auth.tokens', $result['tokens']);

        return $this->completeLogin($request);
    }

    private function completeLogin(Request $request): RedirectResponse
    {
        $authorization = $request->session()->get('portal.authorization');
        if ($authorization) {
            $url = $this->broker->issue($authorization, $request->session()->get('auth.tokens', []));
            $request->session()->forget(['portal.authorization', 'portal.context', 'portal.journeys.'.$authorization['request_id']]);

            return redirect()->away($url);
        }

        return redirect()->route('portal.home')->with('portal.notice', 'Signed in successfully.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('portal.challenge')) {
            return redirect()->route('portal.login');
        }

        return $this->renderPage($request, 'challenge');
    }

    public function storeChallenge(Request $request): RedirectResponse
    {
        $challenge = $request->session()->get('portal.challenge');
        if (! $challenge || ($challenge['expires_at'] ?? 0) < time()) {
            $request->session()->forget('portal.challenge');

            return redirect()->route('portal.login')->with('portal.error', 'The verification step expired. Please sign in again.');
        }
        $input = $request->validate([
            'code' => [$challenge['name'] === 'NEW_PASSWORD_REQUIRED' ? 'nullable' : 'required', 'string', 'max:20'],
            'password' => [$challenge['name'] === 'NEW_PASSWORD_REQUIRED' ? 'required' : 'nullable', 'string', 'confirmed', 'max:256'],
            'given_name' => ['nullable', 'string', 'max:256'], 'family_name' => ['nullable', 'string', 'max:256'], 'email' => ['nullable', 'email'],
        ]);
        $request->session()->put('portal.authorization', $challenge['authorization'] ?? null);
        try {
            $result = $this->identity->respondToChallenge($challenge, $input, $this->capturePortalContext($request));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return $this->acceptAuthentication($request, $result);
    }

    public function storeRegistration(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'max:256'],
            'first_name' => ['required', 'string', 'max:256'],
            'last_name' => ['required', 'string', 'max:256'],
            'accept_policies' => ['accepted'],
        ]);

        try {
            $result = $this->identity->register($validated);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        if ($result['confirmed']) {
            return redirect()
                ->route('portal.login', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct'), 'email' => $validated['email']])
                ->with('portal.notice', 'Account created successfully. Please sign in.');
        }

        return redirect()
            ->route('portal.register.confirm', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct'), 'email' => $validated['email'], 'username' => $validated['username']])
            ->with('portal.notice', 'Check your email for the confirmation code.');
    }

    public function storeRegistrationConfirmation(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        try {
            $this->identity->confirmRegistration($validated['username'], $validated['code']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'code' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.login', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct'), 'email' => $validated['email']])
            ->with('portal.notice', 'Account confirmed. You can now sign in.');
    }

    public function resendRegistrationConfirmation(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        try {
            $this->identity->resendConfirmation($validated['username']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'username' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.register.confirm', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct'), 'email' => $validated['email'], 'username' => $validated['username']])
            ->with('portal.notice', 'A new confirmation code has been sent.');
    }

    public function storeForgotPassword(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:128'],
        ]);

        try {
            $this->identity->startForgotPassword($validated['email']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.password.reset', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct'), 'email' => $validated['email']])
            ->with('portal.notice', 'A password reset code has been sent.');
    }

    public function storeResetPassword(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:256'],
        ]);

        try {
            $this->identity->confirmForgotPassword($validated['email'], $validated['code'], $validated['password']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'code' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.login', ['portal_request' => $request->session()->get('portal.authorization.request_id', 'direct'), 'email' => $validated['email']])
            ->with('portal.notice', 'Password updated successfully. Please sign in.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $url = $this->broker->logoutUrl($request);
        $this->identity->logout($request->session()->get('auth.tokens.access_token'));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $url ? redirect()->away($url) : redirect()->route('portal.home')->with('portal.notice', 'Signed out successfully.');
    }

    public function redirectToSocialProvider(Request $request, string $provider): RedirectResponse
    {
        $context = $this->capturePortalContext($request);

        try {
            $socialLogin = $this->identity->buildSocialLoginUrl($provider, $context);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', $exception->getMessage());
        }

        $request->session()->put('portal.social_auth', [
            'provider' => $socialLogin['provider'],
            'state' => $socialLogin['state'],
            'context' => $context,
            'authorization' => $request->session()->get('portal.authorization'),
            'nonce' => $socialLogin['nonce'],
            'verifier' => $socialLogin['verifier'],
            'expires_at' => time() + config('sso.state_ttl_seconds'),
        ]);

        return redirect()->away($socialLogin['url']);
    }

    public function handleSocialCallback(Request $request): RedirectResponse
    {
        $socialAuth = $request->session()->pull('portal.social_auth');
        $context = is_array($socialAuth['context'] ?? null)
            ? $socialAuth['context']
            : $request->session()->get('portal.context', []);

        $expectedState = is_string($socialAuth['state'] ?? null) ? $socialAuth['state'] : null;
        $returnedState = $request->query('state');
        $code = $request->query('code');

        if (($socialAuth['expires_at'] ?? 0) <= time() || ! is_string($expectedState) || ! is_string($returnedState) || ! hash_equals($expectedState, $returnedState)) {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', 'The social sign-in request could not be verified. Please try again.');
        }

        if ($request->filled('error')) {
            return redirect()->route('portal.login', array_filter($context))->with('portal.error', 'Social sign-in was cancelled or could not be completed. Please try again.');
        }

        if (! is_string($code) || $code === '') {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', 'No authorization code was returned by the social provider.');
        }

        try {
            $result = $this->identity->exchangeAuthorizationCode($code, $context, $socialAuth);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', $exception->getMessage());
        }

        $request->session()->put('portal.authorization', $socialAuth['authorization'] ?? null);

        return $this->acceptAuthentication($request, $result);
    }

    private function capturePortalContext(Request $request): array
    {
        $authorization = $this->broker->capture($request);
        $consumer = $authorization['consumer'] ?? null;

        return [
            'portal_request' => $authorization['request_id'] ?? 'direct',
            'consumer' => $consumer,
            'application_name' => $consumer ? config("sso.consumers.{$consumer}.label") : null,
        ];
    }

    private function renderPage(Request $request, string $page, ?array $authStatus = null): View
    {
        return view('portal.index', [
            'page' => $page,
            'portalContext' => $this->capturePortalContext($request),
            'authStatus' => $authStatus ?? $request->session()->get('auth.status', [
                'authenticated' => false,
                'user' => null,
            ]),
            'socialProviders' => $this->identity->socialProviders(),
            'applications' => Arr::only(config('sso.consumers', []), ['cloud', 'backstage', 'guestlist', 'musicteacher']),
        ]);
    }

    private function renderPolicyPage(Request $request, string $title, string $intro): View
    {
        return view('portal.policy', [
            'page' => 'policy',
            'authStatus' => $request->session()->get('auth.status', [
                'authenticated' => false,
                'user' => null,
            ]),
            'title' => $title,
            'intro' => $intro,
        ]);
    }
}
