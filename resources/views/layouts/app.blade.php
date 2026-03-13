<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    @php
        $sessionUser = $authStatus['user'] ?? null;
        $displayName = $sessionUser['name'] ?? null;
        $displayRole = $sessionUser['user_role'] ?? null;
        $email = strtolower(trim((string) ($sessionUser['email'] ?? '')));
        $gravatarUrl = $email !== '' ? 'https://www.gravatar.com/avatar/'.md5($email).'?d=404&s=96' : null;
        $footerLogo = file_get_contents(resource_path('assets/next-gen-logo-small.svg'));
        $initials = collect(explode(' ', (string) $displayName))
            ->filter()
            ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
            ->take(2)
            ->implode('');
        $initials = $initials !== '' ? $initials : 'AU';
    @endphp
    <body class="min-h-screen bg-[#f3f3f3] text-[#383d42] antialiased">
        <div class="flex min-h-screen flex-col">
            @if ($sessionUser)
                <header class="flex h-16 items-center justify-end gap-4 border-b border-[#d9d9d9] bg-[#f6f6f6] px-5 lg:px-8">
                    <div class="hidden items-center gap-3 lg:flex">
                        <div class="relative flex h-11 w-11 items-center justify-center overflow-hidden rounded-full bg-[#d7c1aa] text-sm font-bold text-[#3a3e43]">
                            @if ($gravatarUrl)
                                <img
                                    alt="{{ $displayName ?: 'User avatar' }}"
                                    class="h-full w-full object-cover"
                                    src="{{ $gravatarUrl }}"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                >
                            @endif
                            <span class="absolute inset-0 {{ $gravatarUrl ? 'hidden' : 'flex' }} items-center justify-center">{{ $initials }}</span>
                        </div>
                        <div class="text-sm">
                            <div class="font-semibold text-[#2f3438]">{{ $displayName ?: 'Authenticated user' }}</div>
                            <div class="text-[#7b7f83]">{{ $displayRole ?: 'Signed in' }}</div>
                        </div>
                    </div>
                </header>
            @endif

            <div class="{{ $sessionUser ? 'flex-1' : 'flex flex-1 items-center' }}">
                @yield('content')
            </div>

            <footer class="flex justify-center px-6 pb-8 pt-4 lg:pb-10">
                <div aria-label="Next Gen Backstage" class="h-auto w-[132px] opacity-90 text-[#1d1d1b]">
                    {!! $footerLogo !!}
                </div>
            </footer>
        </div>
    </body>
</html>
