@extends('layouts.app')

@php
    $titles = [
        'status' => 'Portal Status',
        'login' => 'Sign In',
        'register' => 'Create Account',
        'confirm-registration' => 'Confirm Account',
        'forgot-password' => 'Forgot Password',
        'reset-password' => 'Reset Password',
    ];

    $descriptions = [
        'status' => 'Welcome to the central sign-in portal.',
        'login' => 'Use your Cognito-backed account to sign in.',
        'register' => 'Create a new account on the central sign-in portal.',
        'confirm-registration' => 'Enter the confirmation code we sent to your email.',
        'forgot-password' => 'Request a password reset code.',
        'reset-password' => 'Choose a new password and finish the reset.',
    ];

    $defaultEmail = request('email', old('email'));
    $defaultUsername = request('username', old('username'));
@endphp

@section('content')
    @php
        $isAuthenticated = (bool) ($authStatus['authenticated'] ?? false);
        $showPortalOverview = $isAuthenticated && $page === 'status';
        $mainWidth = $showPortalOverview ? 'max-w-7xl' : 'max-w-xl';
    @endphp

    <main class="mx-auto flex w-full {{ $mainWidth }} flex-col gap-8 px-5 py-8 lg:px-10">
        @if ($showPortalOverview)
            <section class="space-y-2">
                <h1 class="text-4xl tracking-tight text-[#33373c] lg:text-5xl">
                    @if (!empty($authStatus['user']['name']))
                        Hi {{ $authStatus['user']['name'] }}
                    @else
                        {{ $titles[$page] ?? 'Auth Portal' }}
                    @endif
                </h1>
                <p class="text-[#4d5257]">{{ $descriptions[$page] ?? $descriptions['status'] }}</p>
            </section>
        @endif

        @if (session('portal.notice'))
            <div class="rounded-xl border border-[#cbe7cf] bg-[#eff9f1] px-5 py-4 text-sm text-[#2f5f37]">
                {{ session('portal.notice') }}
            </div>
        @endif

        @if (session('portal.error'))
            <div class="rounded-xl border border-[#edd7b5] bg-[#fff7e8] px-5 py-4 text-sm text-[#8a6130]">
                {{ session('portal.error') }}
            </div>
        @endif

        @if ($showPortalOverview)
            <section class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-[#dddddd] bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <div class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                        <div>
                            <h2 class="text-2xl text-[#3a3e43]">Account</h2>
                            <dl class="mt-4 space-y-2 text-[#4b5055]">
                                <div><span class="font-bold text-[#373b40]">Consumer:</span> {{ $portalContext['consumer'] ?: 'Standalone visit' }}</div>
                                <div><span class="font-bold text-[#373b40]">Origin:</span> {{ $portalContext['origin'] ?: 'Direct visit' }}</div>
                                <div class="break-all"><span class="font-bold text-[#373b40]">Return URL:</span> {{ $portalContext['redirect_to'] ?: 'Stay on auth app' }}</div>
                            </dl>
                        </div>

                        <div>
                            <h3 class="text-xl text-[#3a3e43]">Session</h3>
                            <div class="mt-4 text-2xl font-bold text-[#373b40]">Signed in</div>
                            <p class="mt-3 text-[#4b5055]">You have an active authenticated session on this portal.</p>
                        </div>
                    </div>

                    <div class="mt-6 border-t border-[#d7d7d7] pt-5">
                        <a class="inline-flex items-center gap-3 font-medium text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.home', array_filter($portalContext)) }}">
                            Manage auth session
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>

                <div class="rounded-xl border border-[#dddddd] bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <h2 class="text-2xl text-[#3a3e43]">Sign-in context</h2>
                    <dl class="mt-5 space-y-3 text-[#4b5055]">
                        <div><span class="font-bold text-[#373b40]">Mode:</span> {{ $portalContext['mode'] ?: 'status' }}</div>
                        <div><span class="font-bold text-[#373b40]">Region:</span> {{ config('services.cognito.region') }}</div>
                        <div class="break-all"><span class="font-bold text-[#373b40]">Client ID:</span> {{ config('services.cognito.client_id') ?: 'Not configured' }}</div>
                    </dl>
                    <div class="mt-6 border-t border-[#d7d7d7] pt-5 text-[#3da7c7]">
                        Connected to Cognito backend
                    </div>
                </div>

                <div class="rounded-xl border border-[#dddddd] bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <h2 class="text-2xl text-[#3a3e43]">Portal status</h2>
                    <div class="mt-5 text-2xl font-bold text-[#373b40]">Signed in</div>
                    <p class="mt-2 text-[#4b5055]">This reflects the current Laravel session on the auth portal.</p>
                    <div class="mt-6 border-t border-[#d7d7d7] pt-5">
                        <a class="inline-flex items-center gap-3 font-medium text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.home', array_filter($portalContext)) }}">
                            View session details
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>

                <div class="flex h-full flex-col rounded-xl border border-[#dddddd] bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <h2 class="text-2xl text-[#3a3e43]">Session details</h2>
                    @if (!empty($authStatus['user']))
                        <dl class="mt-5 space-y-3 text-[#4b5055]">
                            <div><span class="font-bold text-[#373b40]">Name:</span> {{ $authStatus['user']['name'] ?? 'Unknown' }}</div>
                            <div><span class="font-bold text-[#373b40]">Email:</span> {{ $authStatus['user']['email'] ?? 'Unknown' }}</div>
                            <div><span class="font-bold text-[#373b40]">Role:</span> {{ $authStatus['user']['user_role'] ?? 'Unknown' }}</div>
                            <div><span class="font-bold text-[#373b40]">Consumer:</span> {{ $authStatus['user']['consumer'] ?? 'Direct' }}</div>
                        </dl>
                    @else
                        <p class="mt-5 text-[#4b5055]">No authenticated user in the current session.</p>
                    @endif
                    <div class="mt-auto flex justify-end border-t border-[#d7d7d7] pt-5">
                        <a class="inline-flex items-center justify-center rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-5 py-3 text-base font-semibold text-[#4a4f54] transition hover:border-[#3da7c7] hover:text-[#3da7c7]" href="{{ route('portal.logout') }}">
                            Logout
                        </a>
                    </div>
                </div>
            </section>
        @else
            <section class="mx-auto w-full">
                <div class="rounded-xl border border-[#dddddd] bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    @if ($page === 'login')
                        <form class="space-y-4" method="POST" action="{{ route('portal.login.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <h2 class="text-2xl text-[#3a3e43]">Sign In</h2>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Email or username</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="email" value="{{ $defaultEmail }}">
                                @error('email')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Password</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="password" name="password">
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                                Sign in
                            </button>

                            <a class="inline-flex w-full items-center justify-center rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-5 py-3 text-base font-semibold text-[#4a4f54] transition hover:border-[#3da7c7] hover:text-[#3da7c7]" href="{{ route('portal.register', array_filter($portalContext)) }}">
                                Register
                            </a>

                            <div class="flex items-center gap-3 pt-2">
                                <div class="h-px flex-1 bg-[#d7d7d7]"></div>
                                <span class="text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">or continue with</span>
                                <div class="h-px flex-1 bg-[#d7d7d7]"></div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($socialProviders as $provider)
                                    <a
                                        class="inline-flex items-center justify-center rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-center font-semibold text-[#4a4f54] transition hover:border-[#3da7c7] hover:text-[#3da7c7]"
                                        href="{{ route('portal.login.provider', array_merge(['provider' => $provider['slug']], array_filter($portalContext))) }}"
                                    >
                                        Continue with {{ $provider['label'] }}
                                    </a>
                                @endforeach
                            </div>

                            <div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 pt-2 text-sm">
                                <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.register', array_filter($portalContext)) }}">Create account</a>
                                <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.password.forgot', array_filter($portalContext)) }}">Forgot password</a>
                            </div>
                        </form>
                    @elseif ($page === 'register')
                        <form class="space-y-4" method="POST" action="{{ route('portal.register.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <h2 class="text-2xl text-[#3a3e43]">Create Account</h2>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Username</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="username" value="{{ $defaultUsername }}" placeholder="Username">
                                @error('username')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">First name</label>
                                    <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="first_name" value="{{ old('first_name') }}" placeholder="First name">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Last name</label>
                                    <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="last_name" value="{{ old('last_name') }}" placeholder="Last name">
                                </div>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Email</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
                                @error('email')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Password</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="password" name="password" placeholder="Password">
                                @error('password')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Confirm password</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="password" name="password_confirmation" placeholder="Confirm password">
                            </div>
                            <div class="space-y-3 rounded-xl border border-[#e3e3e3] bg-[#fafafa] px-4 py-4 text-sm text-[#4b5055]">
                                <label class="flex items-start gap-3">
                                    <input class="mt-1 h-4 w-4 rounded border-[#c9c9c9] text-[#3da7c7] focus:ring-[#3da7c7]" type="checkbox" name="accept_policies" value="1" {{ old('accept_policies') ? 'checked' : '' }}>
                                    <span>
                                        I agree to the
                                        <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.policy.privacy') }}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
                                        and
                                        <a class="font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.policy.terms') }}" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.
                                    </span>
                                </label>
                                @error('accept_policies')<p class="text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                                Create account
                            </button>
                            <a class="inline-flex items-center gap-2 text-sm font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.login', array_filter($portalContext)) }}">
                                <span aria-hidden="true">←</span>
                                Back to sign in
                            </a>
                        </form>
                    @elseif ($page === 'confirm-registration')
                        <form class="space-y-4" method="POST" action="{{ route('portal.register.confirm.store') }}">
                            @csrf
                            <h2 class="text-2xl text-[#3a3e43]">Confirm Account</h2>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Username</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="username" value="{{ $defaultUsername }}" placeholder="Username">
                                @error('username')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Email</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Confirmation code</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="code" value="{{ old('code') }}" placeholder="Confirmation code">
                                @error('code')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                                Confirm account
                            </button>
                            <a class="inline-flex items-center gap-2 text-sm font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.register', array_filter($portalContext)) }}">
                                <span aria-hidden="true">←</span>
                                Back to register
                            </a>
                        </form>
                        <form class="mt-4" method="POST" action="{{ route('portal.register.resend') }}">
                            @csrf
                            <input type="hidden" name="username" value="{{ $defaultUsername }}">
                            <input type="hidden" name="email" value="{{ $defaultEmail }}">
                            <button class="inline-flex w-full items-center justify-center rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-5 py-3 text-base font-semibold text-[#4a4f54] transition hover:border-[#3da7c7] hover:text-[#3da7c7]" type="submit">
                                Resend confirmation code
                            </button>
                        </form>
                    @elseif ($page === 'forgot-password')
                        <form class="space-y-4" method="POST" action="{{ route('portal.password.forgot.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <h2 class="text-2xl text-[#3a3e43]">Forgot Password</h2>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Email</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
                                @error('email')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                                Send reset code
                            </button>
                            <a class="inline-flex items-center gap-2 text-sm font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.login', array_filter($portalContext)) }}">
                                <span aria-hidden="true">←</span>
                                Back to sign in
                            </a>
                        </form>
                    @elseif ($page === 'reset-password')
                        <form class="space-y-4" method="POST" action="{{ route('portal.password.reset.store') }}">
                            @csrf
                            <h2 class="text-2xl text-[#3a3e43]">Reset Password</h2>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Email</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Reset code</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="text" name="code" value="{{ old('code') }}" placeholder="Reset code">
                                @error('code')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">New password</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="password" name="password" placeholder="New password">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-semibold uppercase tracking-[0.14em] text-[#7c8186]">Confirm new password</label>
                                <input class="w-full rounded-xl border border-[#d7d7d7] bg-[#fafafa] px-4 py-3 text-base text-[#33373c] outline-none focus:border-[#3da7c7]" type="password" name="password_confirmation" placeholder="Confirm new password">
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#3da7c7] px-5 py-3 text-base font-semibold text-white transition hover:bg-[#3094b2]" type="submit">
                                Update password
                            </button>
                            <a class="inline-flex items-center gap-2 text-sm font-semibold text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.password.forgot', array_filter($portalContext)) }}">
                                <span aria-hidden="true">←</span>
                                Back to reset request
                            </a>
                        </form>
                    @endif
                </div>
            </section>
        @endif
    </main>
@endsection
