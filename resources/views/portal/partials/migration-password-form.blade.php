@php
    $requirements = $setup['requirements'] ?? ['minimum_length' => 8, 'uppercase' => true, 'lowercase' => true, 'numbers' => true, 'symbols' => true];
    $requirementLabels = ['minimum_length' => 'At least '.$requirements['minimum_length'].' characters', 'uppercase' => 'An uppercase letter', 'lowercase' => 'A lowercase letter', 'numbers' => 'A number', 'symbols' => 'A symbol'];
@endphp
<div class="portal-dialog-panel password-setup-panel">
    <div class="login-progress-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg></div>
    <p class="login-progress-eyebrow">YOUR ACCOUNT IS VERIFIED</p>
    <h2 class="portal-heading login-progress-title" id="migration-password-title" tabindex="-1">Create your single sign-on password</h2>
    <p class="portal-copy" id="migration-password-description" data-password-setup-description>We verified your account. Create a stronger password to finish setting up single sign-on.</p>
    <form method="POST" action="{{ route('portal.login.migration.password.store') }}" class="password-setup-form" data-password-setup>
        @csrf
        <input type="hidden" name="challenge" value="{{ $setup['challenge_id'] ?? '' }}">
        <input type="hidden" name="login_progress" value="{{ $loginAttempt ?? '' }}">
        <p class="login-form-error rounded-xl border p-4 text-sm {{ $errors->any() ? '' : 'hidden' }}" role="alert" data-password-setup-error>{{ $errors->first() }}</p>
        @foreach (['password' => ['migration-new-password', 'New password'], 'password_confirmation' => ['migration-password-confirmation', 'Confirm new password']] as $name => [$id, $label])
            <div>
                <label class="portal-label mb-2 block text-sm font-semibold" for="{{ $id }}">{{ $label }}</label>
                <div class="password-field">
                    <input class="portal-input w-full rounded-xl border px-4 py-3 text-base outline-none focus:border-[#3da7c7]" id="{{ $id }}" name="{{ $name }}" type="password" autocomplete="new-password" required maxlength="256" @if($name === 'password') minlength="{{ $requirements['minimum_length'] }}" @endif>
                    <button class="password-visibility-toggle" type="button" data-password-toggle aria-controls="{{ $id }}" aria-label="Show password" aria-pressed="false" title="Show password" hidden>
                        <svg data-password-show aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg data-password-hide aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" hidden><path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.2 6.2A19 19 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.5M10 10a3 3 0 0 0 4 4"/></svg>
                    </button>
                </div>
            </div>
        @endforeach
        <p class="portal-copy text-sm" data-password-match aria-live="polite"></p>
        <ul class="password-requirements" data-password-requirements aria-label="New password requirements">
            @foreach ($requirementLabels as $key => $label)
                @if ($requirements[$key])<li data-requirement="{{ $key }}"><span aria-hidden="true">○</span><span>{{ $label }}</span></li>@endif
            @endforeach
        </ul>
        <p class="portal-copy password-setup-note">Use this new password for future single sign-on. Your original platform password will stay the same.</p>
        <button type="submit" class="password-setup-submit">Create password and continue</button>
        @if ($standalone ?? false)
            <a class="password-setup-cancel portal-copy" href="{{ route('portal.login') }}">Back to sign in</a>
        @else
            <button type="button" class="password-setup-cancel portal-copy" data-password-setup-cancel>Back to sign in</button>
        @endif
    </form>
</div>
