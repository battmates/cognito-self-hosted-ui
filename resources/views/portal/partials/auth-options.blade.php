@php
    $activeAuthPanel = old('auth_panel', $page === 'register' ? 'register' : 'login');
    $loginUrl = route('portal.login', array_filter($portalContext));
    $registerUrl = route('portal.register', array_filter($portalContext));
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
            <form class="space-y-4" method="POST" action="{{ route('portal.login.store') }}">
                @csrf
                @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                <input type="hidden" name="auth_panel" value="login">

                <div class="space-y-1">
                    <h2 class="portal-heading text-2xl">Sign In</h2>
                    <p class="portal-copy text-sm">Access your account with your email or a connected provider.</p>
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="login-email">Email or username</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="login-email" type="text" name="email" value="{{ $defaultEmail }}" autocomplete="username">
                    @if ($activeAuthPanel === 'login')
                        @error('email')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div>
                    <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]" for="login-password">Password</label>
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="login-password" type="password" name="password" autocomplete="current-password">
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
                    <p class="portal-copy text-sm">Register for a new account with your details.</p>
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
                            <button class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" onclick="document.getElementById('privacy-policy-modal').showModal()" type="button">Privacy Policy</button>
                            and
                            <button class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" onclick="document.getElementById('terms-modal').showModal()" type="button">Terms and Conditions</button>.
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

    <dialog class="portal-dialog fixed inset-0 m-auto w-full max-w-2xl rounded-2xl border p-0 shadow-[0_16px_60px_rgba(0,0,0,0.16)] backdrop:bg-[rgba(29,29,27,0.45)]" id="privacy-policy-modal">
        <div class="portal-dialog-panel space-y-5 p-6 lg:p-8">
            <div class="flex items-start justify-between gap-4">
                <h3 class="portal-heading">Privacy Policy</h3>
                <button class="portal-secondary-button rounded-full border px-3 py-1 text-sm font-semibold transition" onclick="document.getElementById('privacy-policy-modal').close()" type="button">Close</button>
            </div>
            <div class="portal-copy space-y-4 text-sm leading-6">
                <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris id ligula sed neque pretium cursus. Integer ac mauris vitae arcu laoreet sodales. Donec vitae nisi velit. Nulla facilisi.</p>
                <p>Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae; Cras ullamcorper felis nec sapien convallis, a mattis nibh commodo. Sed in gravida orci. Nulla facilisi.</p>
                <p>Curabitur posuere, leo nec finibus suscipit, purus lacus posuere eros, vitae luctus lorem mauris sed est. Pellentesque eleifend purus id elit placerat, non egestas turpis ultricies.</p>
                <p>Nam et sem non massa commodo malesuada. Duis tristique sollicitudin erat, nec sodales neque facilisis non. Sed malesuada convallis dui, sed interdum turpis tincidunt sit amet.</p>
            </div>
        </div>
    </dialog>

    <dialog class="portal-dialog fixed inset-0 m-auto w-full max-w-2xl rounded-2xl border p-0 shadow-[0_16px_60px_rgba(0,0,0,0.16)] backdrop:bg-[rgba(29,29,27,0.45)]" id="terms-modal">
        <div class="portal-dialog-panel space-y-5 p-6 lg:p-8">
            <div class="flex items-start justify-between gap-4">
                <h3 class="portal-heading">Terms and Conditions</h3>
                <button class="portal-secondary-button rounded-full border px-3 py-1 text-sm font-semibold transition" onclick="document.getElementById('terms-modal').close()" type="button">Close</button>
            </div>
            <div class="portal-copy space-y-4 text-sm leading-6">
                <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris id ligula sed neque pretium cursus. Integer ac mauris vitae arcu laoreet sodales. Donec vitae nisi velit. Nulla facilisi.</p>
                <p>Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae; Cras ullamcorper felis nec sapien convallis, a mattis nibh commodo. Sed in gravida orci. Nulla facilisi.</p>
                <p>Curabitur posuere, leo nec finibus suscipit, purus lacus posuere eros, vitae luctus lorem mauris sed est. Pellentesque eleifend purus id elit placerat, non egestas turpis ultricies.</p>
                <p>Nam et sem non massa commodo malesuada. Duis tristique sollicitudin erat, nec sodales neque facilisis non. Sed malesuada convallis dui, sed interdum turpis tincidunt sit amet.</p>
            </div>
        </div>
    </dialog>
</section>
