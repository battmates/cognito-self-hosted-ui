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
        $showsAuthOptions = in_array($page, ['login', 'register'], true);
        $mainWidth = ($showPortalOverview || $showsAuthOptions) ? 'max-w-7xl' : 'max-w-xl';
    @endphp

    <main class="portal-shell mx-auto flex w-full {{ $mainWidth }} flex-col gap-8 px-5 py-8 lg:px-10">
        @if ($showPortalOverview)
            <section class="space-y-2">
                <h1 class="portal-title text-4xl tracking-tight lg:text-5xl">
                    @if (!empty($authStatus['user']['name']))
                        Hi {{ $authStatus['user']['name'] }}
                    @else
                        {{ $titles[$page] ?? 'Auth Portal' }}
                    @endif
                </h1>
                <p class="portal-copy">{{ $descriptions[$page] ?? $descriptions['status'] }}</p>
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
                <div class="portal-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <div class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                        <div>
                            <h2 class="portal-heading text-2xl">Account</h2>
                            <dl class="portal-copy mt-4 space-y-2">
                                <div><span class="portal-emphasis font-bold">Consumer:</span> {{ $portalContext['consumer'] ?: 'Standalone visit' }}</div>
                                <div><span class="portal-emphasis font-bold">Origin:</span> {{ $portalContext['origin'] ?: 'Direct visit' }}</div>
                                <div class="break-all"><span class="portal-emphasis font-bold">Return URL:</span> {{ $portalContext['redirect_to'] ?: 'Stay on auth app' }}</div>
                            </dl>
                        </div>

                        <div>
                            <h3 class="portal-heading text-xl">Session</h3>
                            <div class="portal-emphasis mt-4 text-2xl font-bold">Signed in</div>
                            <p class="portal-copy mt-3">You have an active authenticated session on this portal.</p>
                        </div>
                    </div>

                    <div class="portal-divider mt-6 border-t pt-5">
                        <a class="inline-flex items-center gap-3 font-medium text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.home', array_filter($portalContext)) }}">
                            Manage auth session
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>

                <div class="portal-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <h2 class="portal-heading text-2xl">Sign-in context</h2>
                    <dl class="portal-copy mt-5 space-y-3">
                        <div><span class="portal-emphasis font-bold">Mode:</span> {{ $portalContext['mode'] ?: 'status' }}</div>
                        <div><span class="portal-emphasis font-bold">Region:</span> {{ config('services.cognito.region') }}</div>
                        <div class="break-all"><span class="portal-emphasis font-bold">Client ID:</span> {{ config('services.cognito.client_id') ?: 'Not configured' }}</div>
                    </dl>
                    <div class="portal-divider mt-6 border-t pt-5 text-[#3da7c7]">
                        Connected to Cognito backend
                    </div>
                </div>

                <div class="portal-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <h2 class="portal-heading text-2xl">Portal status</h2>
                    <div class="portal-emphasis mt-5 text-2xl font-bold">Signed in</div>
                    <p class="portal-copy mt-2">This reflects the current Laravel session on the auth portal.</p>
                    <div class="portal-divider mt-6 border-t pt-5">
                        <a class="inline-flex items-center gap-3 font-medium text-[#3da7c7] transition hover:text-[#2b8ca8]" href="{{ route('portal.home', array_filter($portalContext)) }}">
                            View session details
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>

                <div class="portal-card flex h-full flex-col rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    <h2 class="portal-heading text-2xl">Session details</h2>
                    @if (!empty($authStatus['user']))
                        <dl class="portal-copy mt-5 space-y-3">
                            <div><span class="portal-emphasis font-bold">Name:</span> {{ $authStatus['user']['name'] ?? 'Unknown' }}</div>
                            <div><span class="portal-emphasis font-bold">Email:</span> {{ $authStatus['user']['email'] ?? 'Unknown' }}</div>
                            <div><span class="portal-emphasis font-bold">Role:</span> {{ $authStatus['user']['user_role'] ?? 'Unknown' }}</div>
                            <div><span class="portal-emphasis font-bold">Consumer:</span> {{ $authStatus['user']['consumer'] ?? 'Direct' }}</div>
                        </dl>
                    @else
                        <p class="portal-copy mt-5">No authenticated user in the current session.</p>
                    @endif
                    <div class="portal-divider mt-auto flex justify-end border-t pt-5">
                        <a class="portal-secondary-button inline-flex items-center justify-center rounded-xl border px-5 py-3 text-base font-semibold transition" href="{{ route('portal.logout') }}">
                            Logout
                        </a>
                    </div>
                </div>
            </section>
        @else
            <section class="mx-auto w-full">
                @if (in_array($page, ['login', 'register'], true))
                    @include('portal.partials.auth-options')
                @else
                    <div class="portal-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    @if ($page === 'confirm-registration')
                        <form class="space-y-4" method="POST" action="{{ route('portal.register.confirm.store') }}">
                            @csrf
                            <h2 class="portal-heading text-2xl">Confirm Account</h2>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Username</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="text" name="username" value="{{ $defaultUsername }}" placeholder="Username">
                                @error('username')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Email</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
                            </div>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Confirmation code</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="text" name="code" value="{{ old('code') }}" placeholder="Confirmation code">
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
                            <button class="portal-secondary-button inline-flex w-full items-center justify-center rounded-xl border px-5 py-3 text-base font-semibold transition" type="submit">
                                Resend confirmation code
                            </button>
                        </form>
                    @elseif ($page === 'forgot-password')
                        <form class="space-y-4" method="POST" action="{{ route('portal.password.forgot.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <h2 class="portal-heading text-2xl">Forgot Password</h2>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Email</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
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
                            <h2 class="portal-heading text-2xl">Reset Password</h2>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Email</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="email" name="email" value="{{ $defaultEmail }}" placeholder="Email">
                            </div>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Reset code</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="text" name="code" value="{{ old('code') }}" placeholder="Reset code">
                                @error('code')<p class="mt-2 text-sm text-[#b56f2f]">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">New password</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="password" name="password" placeholder="New password">
                            </div>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Confirm new password</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="password" name="password_confirmation" placeholder="Confirm new password">
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
                @endif
            </section>
        @endif
    </main>
@endsection
