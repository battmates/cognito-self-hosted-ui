<?php

namespace App\Http\Controllers;

use App\Services\CognitoIdentityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthPortalController extends Controller
{
    public function __construct(
        private readonly CognitoIdentityService $identity,
    ) {
    }

    public function home(Request $request): View
    {
        $authStatus = $request->session()->get('auth.status', [
            'authenticated' => false,
            'user' => null,
        ]);

        return $this->renderPage($request, ($authStatus['authenticated'] ?? false) ? 'status' : 'login', $authStatus);
    }

    public function login(Request $request): View
    {
        return $this->renderPage($request, 'login');
    }

    public function register(Request $request): View
    {
        return $this->renderPage($request, 'register');
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
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        try {
            $result = $this->identity->login($validated['email'], $validated['password'], $context);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        $request->session()->put('auth.status', [
            'authenticated' => true,
            'user' => $result['user'],
        ]);
        $request->session()->put('auth.tokens', $result['tokens']);

        try {
            $returnUrl = $this->identity->buildReturnUrl($context, $result['user']);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('portal.home')
                ->with('portal.error', $exception->getMessage());
        }

        if ($returnUrl) {
            return redirect()->away($returnUrl);
        }

        return redirect()
            ->route('portal.home')
            ->with('portal.notice', 'Signed in successfully. No origin app was provided, so you remain on the auth portal.');
    }

    public function storeRegistration(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed'],
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
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
                ->route('portal.login', ['email' => $validated['email']])
                ->with('portal.notice', 'Account created successfully. Please sign in.');
        }

        return redirect()
            ->route('portal.register.confirm', ['email' => $validated['email'], 'username' => $validated['username']])
            ->with('portal.notice', 'Check your email for the confirmation code.');
    }

    public function storeRegistrationConfirmation(Request $request): RedirectResponse
    {
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
            ->route('portal.login', ['email' => $validated['email']])
            ->with('portal.notice', 'Account confirmed. You can now sign in.');
    }

    public function resendRegistrationConfirmation(Request $request): RedirectResponse
    {
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
            ->route('portal.register.confirm', ['email' => $validated['email'], 'username' => $validated['username']])
            ->with('portal.notice', 'A new confirmation code has been sent.');
    }

    public function storeForgotPassword(Request $request): RedirectResponse
    {
        $this->capturePortalContext($request);
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $this->identity->startForgotPassword($validated['email']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.password.reset', ['email' => $validated['email']])
            ->with('portal.notice', 'A password reset code has been sent.');
    }

    public function storeResetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ]);

        try {
            $this->identity->confirmForgotPassword($validated['email'], $validated['code'], $validated['password']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'code' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.login', ['email' => $validated['email']])
            ->with('portal.notice', 'Password updated successfully. Please sign in.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->identity->logout($request->session()->get('auth.tokens.access_token'));
        $request->session()->forget('auth.status');
        $request->session()->forget('auth.tokens');
        $request->session()->forget('portal.context');
        $request->session()->forget('portal.social_auth');

        return redirect()
            ->route('portal.home')
            ->with('portal.notice', 'Signed out successfully.');
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
        ]);

        return redirect()->away($socialLogin['url']);
    }

    public function handleSocialCallback(Request $request): RedirectResponse
    {
        $socialAuth = $request->session()->get('portal.social_auth');
        $context = is_array($socialAuth['context'] ?? null)
            ? $socialAuth['context']
            : $request->session()->get('portal.context', []);

        if ($request->filled('error')) {
            $message = $request->query('error_description') ?: $request->query('error') ?: 'Social sign-in failed.';

            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', is_string($message) ? $message : 'Social sign-in failed.');
        }

        $expectedState = is_string($socialAuth['state'] ?? null) ? $socialAuth['state'] : null;
        $returnedState = $request->query('state');
        $code = $request->query('code');

        if (! is_string($expectedState) || ! is_string($returnedState) || ! hash_equals($expectedState, $returnedState)) {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', 'The social sign-in request could not be verified. Please try again.');
        }

        if (! is_string($code) || $code === '') {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', 'No authorization code was returned by the social provider.');
        }

        try {
            $result = $this->identity->exchangeAuthorizationCode($code, $context);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('portal.login', array_filter($context))
                ->with('portal.error', $exception->getMessage());
        }

        $request->session()->forget('portal.social_auth');
        $request->session()->put('auth.status', [
            'authenticated' => true,
            'user' => $result['user'],
        ]);
        $request->session()->put('auth.tokens', $result['tokens']);

        try {
            $returnUrl = $this->identity->buildReturnUrl($context, $result['user']);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('portal.home')
                ->with('portal.error', $exception->getMessage());
        }

        if ($returnUrl) {
            return redirect()->away($returnUrl);
        }

        return redirect()
            ->route('portal.home')
            ->with('portal.notice', sprintf(
                'Signed in with %s successfully. No origin app was provided, so you remain on the auth portal.',
                $socialAuth['provider'] ?? 'the selected provider'
            ));
    }

    private function capturePortalContext(Request $request): array
    {
        $context = [
            'consumer' => $request->input('consumer') ?: $request->query('consumer') ?: $request->session()->get('portal.context.consumer'),
            'redirect_to' => $request->input('redirect_to') ?: $request->query('redirect_to') ?: $request->session()->get('portal.context.redirect_to'),
            'origin' => $request->input('origin') ?: $request->query('origin') ?: $request->session()->get('portal.context.origin'),
            'mode' => $request->input('mode') ?: $request->query('mode') ?: $request->session()->get('portal.context.mode'),
        ];

        $request->session()->put('portal.context', $context);

        return $context;
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
