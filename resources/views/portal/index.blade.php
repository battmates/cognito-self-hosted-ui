@extends('layouts.app')

@php
    $titles = [
        'status' => 'Your applications',
        'login' => 'Sign In',
        'register' => 'Create Account',
        'confirm-registration' => 'Confirm Account',
        'forgot-password' => 'Forgot Password',
        'reset-password' => 'Reset Password',
    ];

    $descriptions = [
        'status' => 'One account for your RSL applications. Choose where to go next.',
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

    <main class="portal-shell mx-auto flex w-full {{ $mainWidth }} flex-col gap-8 px-5 {{ $isAuthenticated ? 'py-8' : 'pb-8 pt-24' }} lg:px-10">
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

        @if (!empty($portalContext['application_name']) && !$showPortalOverview)
            <h1 class="portal-heading text-center text-2xl lg:text-3xl">Please login or register to continue to {{ $portalContext['application_name'] }}</h1>
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
            <section class="space-y-4" aria-labelledby="applications-heading">
                <h2 id="applications-heading" class="portal-heading text-2xl">Your applications</h2>
                <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($applications as $application)
                        <a class="portal-card rounded-xl border p-6 transition hover:border-[#3da7c7] {{ empty($application['base_url']) ? 'pointer-events-none opacity-70' : '' }}" @if(!empty($application['base_url'])) href="{{ $application['base_url'] }}" @endif>
                            <div class="flex items-center gap-4"><span class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#3da7c7] font-bold text-white">{{ $application['logo'] }}</span><h3 class="portal-heading text-2xl">{{ $application['label'] }}</h3></div>
                            <p class="portal-copy mt-5">{{ $application['description'] }}</p>
                            <span class="mt-6 inline-flex font-semibold text-[#3da7c7]">{{ empty($application['base_url']) ? 'Coming soon' : 'Open application →' }}</span>
                        </a>
                    @endforeach
                </div>
            </section>

            @if ($authStatus['user']['is_admin'] ?? false)
                <section class="space-y-4 border-t border-[var(--portal-divider)] pt-8" aria-labelledby="management-heading">
                    <div>
                        <h2 id="management-heading" class="portal-heading text-2xl">Management</h2>
                        <p class="portal-copy mt-2">Manage portal users and operational services.</p>
                    </div>
                    <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        <a class="portal-card rounded-xl border p-6 transition hover:border-[#3da7c7]" href="{{ route('portal.admin.users') }}">
                            <h3 class="portal-heading text-2xl">User management</h3>
                            <p class="portal-copy mt-3">Search accounts and manage access.</p>
                            <span class="mt-6 inline-flex font-semibold text-[#3da7c7]">Manage users <span class="ml-3" aria-hidden="true">→</span></span>
                        </a>
                        <a class="portal-card rounded-xl border p-6 transition hover:border-[#3da7c7]" href="{{ route('portal.admin.email-tracking') }}">
                            <h3 class="portal-heading text-2xl">Email tracking</h3>
                            <p class="portal-copy mt-3">Review SES deliverability, engagement and message status.</p>
                            <span class="mt-6 inline-flex font-semibold text-[#3da7c7]">View email tracking <span class="ml-3" aria-hidden="true">→</span></span>
                        </a>
                    </div>
                </section>
            @endif
        @else
            <section class="mx-auto w-full">
                @if (in_array($page, ['login', 'register'], true))
                    @include('portal.partials.auth-options')
                @else
                    <div class="portal-card rounded-xl border p-6 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                    @if ($page === 'challenge')
                        @include('portal.partials.challenge')
                    @elseif ($page === 'confirm-registration')
                        <form class="space-y-4" method="POST" action="{{ route('portal.register.confirm.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
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
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
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
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Email or username</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="text" name="email" value="{{ $defaultEmail }}" placeholder="Email or username">
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
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <h2 class="portal-heading text-2xl">Reset Password</h2>
                            <div>
                                <label class="portal-label mb-2 block text-sm font-semibold uppercase tracking-[0.14em]">Email or username</label>
                                <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" type="text" name="email" value="{{ $defaultEmail }}" placeholder="Email or username">
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
