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
        'status' => 'Direct visits can still sign in here and stay on this app for a simple logged-in status experience.',
        'login' => 'Sign in directly on this app. Credentials are posted to Laravel and authenticated against Cognito server-side.',
        'register' => 'Create new accounts from this app without sending users to Cognito Hosted UI.',
        'confirm-registration' => 'Enter the code Cognito sent after sign-up to activate the account.',
        'forgot-password' => 'Start password recovery from this app and stay on the same branded flow.',
        'reset-password' => 'Enter the password reset code and choose a new password.',
    ];

    $defaultEmail = request('email', old('email'));
@endphp

@section('content')
    <main class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-8 px-6 py-10 lg:px-10">
        <section class="overflow-hidden rounded-[2rem] border border-white/10 bg-white/6 shadow-2xl shadow-black/20 backdrop-blur">
            <div class="h-3 w-full bg-gradient-to-r from-orange-500 via-amber-300 to-orange-500"></div>
            <div class="grid gap-8 p-8 lg:grid-cols-[1.25fr_0.75fr] lg:p-12">
                <div class="space-y-6">
                    <span class="inline-flex items-center gap-3 rounded-full border border-white/10 bg-white/8 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-orange-200">
                        <span class="h-2.5 w-2.5 rounded-full bg-orange-400"></span>
                        Central auth property
                    </span>

                    <div class="space-y-4">
                        <h1 class="max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl">{{ config('app.name') }}</h1>
                        <p class="max-w-2xl text-base leading-7 text-stone-300 sm:text-lg">
                            {{ $descriptions[$page] ?? $descriptions['status'] }}
                        </p>
                    </div>

                    @if (session('portal.notice'))
                        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-100">
                            {{ session('portal.notice') }}
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/7 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Page</p>
                            <p class="mt-2 text-sm font-medium">{{ $titles[$page] ?? ucfirst($page) }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/7 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Consumer</p>
                            <p class="mt-2 text-sm font-medium">{{ $portalContext['consumer'] ?: 'Standalone visit' }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/7 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Return URL</p>
                            <p class="mt-2 text-sm font-medium break-all">{{ $portalContext['redirect_to'] ?: 'Stay on auth app' }}</p>
                        </div>
                    </div>
                </div>

                <aside class="rounded-[1.75rem] bg-stone-900/85 p-6 ring-1 ring-white/10">
                    @if ($page === 'login')
                        <form class="space-y-4" method="POST" action="{{ route('portal.login.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-orange-200">Sign in</p>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Email</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="email" name="email" value="{{ $defaultEmail }}">
                                @error('email')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Password</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="password" name="password">
                                @error('password')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-full bg-orange-400 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:brightness-105" type="submit">
                                Sign in
                            </button>
                        </form>
                    @elseif ($page === 'register')
                        <form class="space-y-4" method="POST" action="{{ route('portal.register.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-orange-200">Create account</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">First name</label>
                                    <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="text" name="first_name" value="{{ old('first_name') }}">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Last name</label>
                                    <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="text" name="last_name" value="{{ old('last_name') }}">
                                </div>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Email</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="email" name="email" value="{{ $defaultEmail }}">
                                @error('email')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Password</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="password" name="password">
                                @error('password')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Confirm password</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="password" name="password_confirmation">
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-full bg-orange-400 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:brightness-105" type="submit">
                                Create account
                            </button>
                        </form>
                    @elseif ($page === 'confirm-registration')
                        <form class="space-y-4" method="POST" action="{{ route('portal.register.confirm.store') }}">
                            @csrf
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-orange-200">Confirm account</p>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Email</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="email" name="email" value="{{ $defaultEmail }}">
                                @error('email')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Confirmation code</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="text" name="code" value="{{ old('code') }}">
                                @error('code')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-full bg-orange-400 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:brightness-105" type="submit">
                                Confirm account
                            </button>
                        </form>
                        <form class="mt-4" method="POST" action="{{ route('portal.register.resend') }}">
                            @csrf
                            <input type="hidden" name="email" value="{{ $defaultEmail }}">
                            <button class="inline-flex w-full items-center justify-center rounded-full border border-white/10 bg-white/8 px-5 py-3 text-sm font-semibold text-stone-100 transition hover:bg-white/12" type="submit">
                                Resend confirmation code
                            </button>
                        </form>
                    @elseif ($page === 'forgot-password')
                        <form class="space-y-4" method="POST" action="{{ route('portal.password.forgot.store') }}">
                            @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-orange-200">Forgot password</p>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Email</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="email" name="email" value="{{ $defaultEmail }}">
                                @error('email')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-full bg-orange-400 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:brightness-105" type="submit">
                                Send reset code
                            </button>
                        </form>
                    @elseif ($page === 'reset-password')
                        <form class="space-y-4" method="POST" action="{{ route('portal.password.reset.store') }}">
                            @csrf
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-orange-200">Reset password</p>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Email</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="email" name="email" value="{{ $defaultEmail }}">
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Reset code</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="text" name="code" value="{{ old('code') }}">
                                @error('code')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">New password</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="password" name="password">
                                @error('password')<p class="mt-2 text-sm text-amber-300">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-2 block text-xs uppercase tracking-[0.22em] text-stone-400">Confirm new password</label>
                                <input class="w-full rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-sm text-white outline-none" type="password" name="password_confirmation">
                            </div>
                            <button class="inline-flex w-full items-center justify-center rounded-full bg-orange-400 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:brightness-105" type="submit">
                                Update password
                            </button>
                        </form>
                    @else
                        <div class="space-y-3">
                            <a class="inline-flex w-full items-center justify-center rounded-full bg-orange-400 px-5 py-3 text-sm font-semibold text-stone-950 transition hover:brightness-105" href="{{ route('portal.login', array_filter($portalContext)) }}">Sign in</a>
                            <a class="inline-flex w-full items-center justify-center rounded-full border border-white/10 bg-white/8 px-5 py-3 text-sm font-semibold text-stone-100 transition hover:bg-white/12" href="{{ route('portal.register', array_filter($portalContext)) }}">Create account</a>
                            <a class="inline-flex w-full items-center justify-center rounded-full border border-white/10 bg-white/8 px-5 py-3 text-sm font-semibold text-stone-100 transition hover:bg-white/12" href="{{ route('portal.password.forgot', array_filter($portalContext)) }}">Forgot password</a>
                            <a class="inline-flex w-full items-center justify-center rounded-full border border-white/10 bg-white/8 px-5 py-3 text-sm font-semibold text-stone-100 transition hover:bg-white/12" href="{{ route('portal.logout') }}">Logout</a>
                        </div>
                    @endif
                </aside>
            </div>
        </section>

        <section>
            <div class="rounded-[2rem] border border-white/10 bg-white/6 p-8 shadow-xl shadow-black/10">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold">Current context</h2>
                        <p class="mt-2 text-sm leading-6 text-stone-300">
                            Direct visits stay local. Brokered visits retain the origin metadata needed for the return trip.
                        </p>
                    </div>
                    <span class="rounded-full border border-white/10 bg-white/8 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-stone-300">
                        {{ ($authStatus['authenticated'] ?? false) ? 'Authenticated' : 'Signed out' }}
                    </span>
                </div>

                <dl class="mt-6 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-stone-950/50 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Origin</dt>
                        <dd class="mt-2 text-sm font-medium break-all">{{ $portalContext['origin'] ?: 'Direct visit' }}</dd>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-950/50 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Mode</dt>
                        <dd class="mt-2 text-sm font-medium">{{ $portalContext['mode'] ?: 'status' }}</dd>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-950/50 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Cognito region</dt>
                        <dd class="mt-2 text-sm font-medium break-all">{{ config('services.cognito.region') }}</dd>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-950/50 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400">Client ID</dt>
                        <dd class="mt-2 text-sm font-medium break-all">{{ config('services.cognito.client_id') ?: 'Not configured' }}</dd>
                    </div>
                </dl>

                @if (($authStatus['authenticated'] ?? false) && !empty($authStatus['user']))
                    <div class="mt-6 rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-100">Signed-in user</p>
                        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs uppercase tracking-[0.22em] text-emerald-200/70">Name</dt>
                                <dd class="mt-1 text-sm text-emerald-50">{{ $authStatus['user']['name'] ?? 'Unknown' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.22em] text-emerald-200/70">Email</dt>
                                <dd class="mt-1 text-sm text-emerald-50">{{ $authStatus['user']['email'] ?? 'Unknown' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.22em] text-emerald-200/70">Consumer</dt>
                                <dd class="mt-1 text-sm text-emerald-50">{{ $authStatus['user']['consumer'] ?? 'Direct' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.22em] text-emerald-200/70">Signed in</dt>
                                <dd class="mt-1 text-sm text-emerald-50">{{ $authStatus['user']['signed_in_at'] ?? 'Unknown' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif
            </div>
        </section>
    </main>
@endsection
