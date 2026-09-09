<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <script>
            (() => {
                const storageKey = 'portal-theme';
                const savedTheme = localStorage.getItem(storageKey);
                const preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.dataset.theme = savedTheme ?? preferredTheme;
            })();
        </script>
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
    <body class="portal-body min-h-screen antialiased">
        <div class="flex min-h-screen flex-col">
            @if ($sessionUser)
                <header class="portal-header flex h-16 items-center justify-between gap-4 border-b px-5 lg:px-8">
                    <button
                        aria-label="Toggle dark mode"
                        class="theme-toggle inline-flex rounded-full border p-3 transition"
                        type="button"
                    >
                        <span class="theme-toggle__icon" aria-hidden="true"></span>
                    </button>
                    <details class="relative"><summary class="flex cursor-pointer list-none items-center gap-3">
                        <div class="portal-avatar relative flex h-11 w-11 items-center justify-center overflow-hidden rounded-full text-sm font-bold">
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
                        <div class="hidden text-sm lg:block">
                            <div class="portal-header-title font-semibold">{{ $displayName ?: 'Authenticated user' }}</div>
                            <div class="portal-header-subtitle">{{ $displayRole ?: 'Signed in' }}</div>
                        </div>
                    </summary><div class="portal-card absolute right-0 z-20 mt-3 w-48 rounded-xl border p-3 shadow-lg"><form method="POST" action="{{ route('portal.logout') }}">@csrf<button class="w-full rounded-lg px-3 py-2 text-left font-semibold">Sign out</button></form></div></details>
                </header>
            @endif

            <div class="relative {{ $sessionUser ? 'flex-1' : 'flex flex-1 items-center' }}">
                @unless ($sessionUser)
                    <div class="absolute right-5 top-5 z-10 lg:right-8 lg:top-8">
                        <button
                            aria-label="Toggle dark mode"
                            class="theme-toggle inline-flex rounded-full border p-3 transition"
                            type="button"
                        >
                            <span class="theme-toggle__icon" aria-hidden="true"></span>
                        </button>
                    </div>
                @endunless
                @yield('content')
            </div>

            <footer class="flex justify-center px-6 pb-8 pt-4 lg:pb-10">
                <div aria-label="Next Gen Backstage" class="portal-footer-logo h-auto w-[132px] opacity-90">
                    {!! $footerLogo !!}
                </div>
            </footer>
        </div>
        <script>
            (() => {
                const storageKey = 'portal-theme';
                const root = document.documentElement;
                const buttons = document.querySelectorAll('.theme-toggle');

                const applyTheme = (theme) => {
                    root.dataset.theme = theme;
                    localStorage.setItem(storageKey, theme);

                    buttons.forEach((button) => {
                        button.setAttribute('aria-pressed', String(theme === 'dark'));
                    });
                };

                buttons.forEach((button) => {
                    button.addEventListener('click', () => {
                        applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
                    });
                });

                applyTheme(root.dataset.theme === 'dark' ? 'dark' : 'light');
            })();
        </script>
        <script>
            (() => {
                const authOptions = document.querySelector('[data-auth-options]');

                if (!authOptions) {
                    return;
                }

                const mobileQuery = window.matchMedia('(max-width: 767px)');
                const tabs = [...authOptions.querySelectorAll('[data-auth-tab]')];
                const panels = [...authOptions.querySelectorAll('[data-auth-panel]')];

                const selectPanel = (name, updateUrl = false) => {
                    authOptions.dataset.activePanel = name;

                    tabs.forEach((tab) => {
                        const isActive = tab.dataset.authTab === name;
                        tab.setAttribute('aria-selected', String(isActive));
                        tab.tabIndex = isActive ? 0 : -1;
                    });

                    panels.forEach((panel) => {
                        const isActive = panel.dataset.authPanel === name;
                        panel.hidden = mobileQuery.matches && !isActive;

                        if (mobileQuery.matches) {
                            panel.setAttribute('aria-hidden', String(!isActive));
                        } else {
                            panel.removeAttribute('aria-hidden');
                        }
                    });

                    if (updateUrl) {
                        const activeTab = tabs.find((tab) => tab.dataset.authTab === name);

                        if (activeTab?.dataset.url) {
                            window.history.replaceState({}, '', activeTab.dataset.url);
                        }
                    }
                };

                tabs.forEach((tab, index) => {
                    tab.addEventListener('click', () => selectPanel(tab.dataset.authTab, true));
                    tab.addEventListener('keydown', (event) => {
                        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) {
                            return;
                        }

                        event.preventDefault();
                        const direction = event.key === 'ArrowRight' ? 1 : -1;
                        const nextTab = tabs[(index + direction + tabs.length) % tabs.length];
                        selectPanel(nextTab.dataset.authTab, true);
                        nextTab.focus();
                    });
                });

                mobileQuery.addEventListener('change', () => selectPanel(authOptions.dataset.activePanel));
                selectPanel(authOptions.dataset.activePanel);
            })();
        </script>
    </body>
</html>
