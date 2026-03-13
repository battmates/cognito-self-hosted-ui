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
        return $this->renderPage($request, 'status');
    }

    public function login(Request $request): View
    {
        return $this->renderPage($request, 'login');
    }

    public function register(Request $request): View
    {
        return $this->renderPage($request, 'register');
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
            'email' => ['required', 'email'],
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed'],
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
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
            ->route('portal.register.confirm', ['email' => $validated['email']])
            ->with('portal.notice', 'Check your email for the confirmation code.');
    }

    public function storeRegistrationConfirmation(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        try {
            $this->identity->confirmRegistration($validated['email'], $validated['code']);
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
            'email' => ['required', 'email'],
        ]);

        try {
            $this->identity->resendConfirmation($validated['email']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('portal.register.confirm', ['email' => $validated['email']])
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

        return redirect()
            ->route('portal.home')
            ->with('portal.notice', 'Signed out successfully.');
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

    private function renderPage(Request $request, string $page): View
    {
        return view('portal.index', [
            'page' => $page,
            'portalContext' => $this->capturePortalContext($request),
            'authStatus' => $request->session()->get('auth.status', [
                'authenticated' => false,
                'user' => null,
            ]),
        ]);
    }
}
