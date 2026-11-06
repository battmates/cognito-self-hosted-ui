@php
    $activeAuthPanel = old('auth_panel', $page === 'register' ? 'register' : 'login');
    $loginUrl = route('portal.login', array_filter($portalContext));
    $registerUrl = route('portal.register', array_filter($portalContext));
    $loginAttempt = (string) \Illuminate\Support\Str::uuid();
    $progressUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('portal.login.progress', now()->addMinutes(30), ['attempt' => $loginAttempt]);
@endphp

<section
    class="auth-options w-full"
    data-auth-options
    data-active-panel="{{ $activeAuthPanel }}"
>
    <div class="auth-tabs mb-4 grid grid-cols-2 rounded-xl p-1" role="tablist" aria-label="Choose an account option">
        <button
            class="auth-tab rounded-lg px-4 py-3 text-sm font-semibold transition"
            id="login-tab"
            type="button"
            role="tab"
            aria-controls="login-panel"
            aria-selected="{{ $activeAuthPanel === 'login' ? 'true' : 'false' }}"
            data-auth-tab="login"
            data-url="{{ $loginUrl }}"
        >
            Sign in
        </button>
        <button
            class="auth-tab rounded-lg px-4 py-3 text-sm font-semibold transition"
            id="register-tab"
            type="button"
            role="tab"
            aria-controls="register-panel"
            aria-selected="{{ $activeAuthPanel === 'register' ? 'true' : 'false' }}"
            data-auth-tab="register"
            data-url="{{ $registerUrl }}"
        >
            Register
        </button>
    </div>

    <div class="auth-options-grid grid items-start gap-6 md:grid-cols-2">
        <div
            class="portal-card auth-option-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)] lg:p-8"
            id="login-panel"
            role="tabpanel"
            aria-labelledby="login-tab"
            data-auth-panel="login"
        >
            <form class="space-y-4" method="POST" action="{{ route('portal.login.store') }}" data-login-form data-progress-url="{{ $progressUrl }}">
                @csrf
                @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                <input type="hidden" name="auth_panel" value="login">
                <input type="hidden" name="login_progress" value="{{ $loginAttempt }}">
                <p class="login-form-error hidden rounded-xl border p-4 text-sm" role="alert" data-login-error></p>

                <div class="space-y-1">
                    <h2 class="portal-heading text-2xl">Sign In</h2>
                    <p class="portal-copy text-sm">Access your account with your email or a connected provider.</p>
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="login-email">Email or username</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="login-email" type="text" name="email" required maxlength="128" value="{{ $defaultEmail }}" autocomplete="username">
                    @if ($activeAuthPanel === 'login')
                        @error('email')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="login-password">Password</label>
                    <div class="password-field">
                        <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="login-password" type="password" name="password" required maxlength="256" autocomplete="current-password">
                        <button class="password-visibility-toggle" type="button" data-password-toggle aria-controls="login-password" aria-label="Show password" aria-pressed="false" title="Show password" hidden>
                            <svg data-password-show aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg data-password-hide aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" hidden><path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.2 6.2A19 19 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.5M10 10a3 3 0 0 0 4 4"/></svg>
                        </button>
                    </div>
                    @if ($activeAuthPanel === 'login')
                        @error('password')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                    Sign in
                </button>

                @if (count($socialProviders))
                    <div class="flex items-center gap-3 pt-2">
                        <div class="portal-rule h-px flex-1"></div>
                        <span class="portal-label text-sm font-semibold uppercase tracking-[0.14em]">or continue with</span>
                        <div class="portal-rule h-px flex-1"></div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-1 lg:grid-cols-2">
                        @foreach ($socialProviders as $provider)
                            <a
                                class="portal-secondary-button inline-flex items-center justify-center gap-2 rounded-xl border px-4 py-3 text-center font-semibold transition"
                                href="{{ route('portal.login.provider', array_merge(['provider' => $provider['slug']], array_filter($portalContext))) }}"
                            >
                                @include('portal.partials.provider-icon', ['slug' => $provider['slug']])
                                Continue with {{ $provider['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-center pt-2 text-sm">
                    <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.password.forgot', array_filter($portalContext)) }}">Forgot password?</a>
                </div>
            </form>
        </div>

        <div
            class="portal-card auth-option-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)] lg:p-8"
            id="register-panel"
            role="tabpanel"
            aria-labelledby="register-tab"
            data-auth-panel="register"
        >
            <form class="space-y-4" method="POST" action="{{ route('portal.register.store') }}">
                @csrf
                @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                <input type="hidden" name="auth_panel" value="register">

                <div class="space-y-1">
                    <h2 class="portal-heading text-2xl">Create Account</h2>
                    <p class="portal-copy text-sm">Register for a new account with your details. If registering as a parent for a child please enter your details here, you will have the option to create linked child accounts later.</p>
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="register-username">Username</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="register-username" type="text" name="username" value="{{ $defaultUsername }}" placeholder="Username" autocomplete="username">
                    @if ($activeAuthPanel === 'register')
                        @error('username')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="register-first-name">First name</label>
                        <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="register-first-name" type="text" name="first_name" required value="{{ old('first_name') }}" placeholder="First name" autocomplete="given-name">
                        @error('first_name')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="register-last-name">Last name</label>
                        <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="register-last-name" type="text" name="last_name" required value="{{ old('last_name') }}" placeholder="Last name" autocomplete="family-name">
                        @error('last_name')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="register-email">Email</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="register-email" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email" autocomplete="email">
                    @if ($activeAuthPanel === 'register')
                        @error('email')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="register-password">Password</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="register-password" type="password" name="password" placeholder="Password" autocomplete="new-password">
                    @if ($activeAuthPanel === 'register')
                        @error('password')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="register-password-confirmation">Confirm password</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="register-password-confirmation" type="password" name="password_confirmation" placeholder="Confirm password" autocomplete="new-password">
                </div>

                <div class="portal-note space-y-3 rounded-xl border px-4 py-4 text-sm">
                    <label class="flex items-start gap-3">
                        <input class="portal-checkbox mt-1 h-4 w-4 rounded text-[#3da7c7] focus:ring-[#3da7c7]" type="checkbox" name="accept_policies" value="1" {{ old('accept_policies') ? 'checked' : '' }}>
                        <span>
                            I agree to the
                            @if (config('sso.privacy_policy_url'))
                                <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ config('sso.privacy_policy_url') }}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
                            @else
                                Privacy Policy
                            @endif
                            and
                            @if (config('sso.terms_url'))
                                <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ config('sso.terms_url') }}" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>
                            @else
                                Terms and Conditions
                            @endif
                            .
                        </span>
                    </label>
                    @if ($activeAuthPanel === 'register')
                        @error('accept_policies')<p class="text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                    Create account
                </button>
            </form>
        </div>
    </div>

    @include('portal.partials.login-progress')
    <dialog class="portal-dialog login-progress-dialog password-setup-dialog" id="migration-password-dialog" aria-labelledby="migration-password-title" aria-describedby="migration-password-description">
        @include('portal.partials.migration-password-form')
    </dialog>
</section>
@vite('resources/js/app.js')
