@extends('layouts.app')
@section('content')
@php
    $currentAction = old('action', 'confirm');
    $displayName = trim(($attributes['given_name'] ?? '').' '.($attributes['family_name'] ?? ''));
@endphp
<main class="portal-shell mx-auto w-full max-w-7xl space-y-6 px-5 py-8 lg:px-10">
    <a class="font-semibold text-[#3da7c7]" href="{{ route('portal.admin.users', $listContext) }}">← Back to users</a>
    <header class="space-y-3">
        <p class="portal-copy text-sm">User profile</p>
        <h1 class="portal-title text-4xl break-all">{{ $displayName !== '' ? $displayName : $selected['Username'] }}</h1>
        @if($displayName !== '')<p class="portal-copy break-all">User name: {{ $selected['Username'] }}</p>@endif
        <p class="portal-copy">{{ $selected['UserStatus'] }} · {{ $selected['Enabled'] ? 'Enabled' : 'Disabled' }}</p>
    </header>
    @unless($writesEnabled)<p class="rounded-xl border border-[#edd7b5] bg-[#fff7e8] p-4 text-[#8a6130]">Read-only mode. You can inspect accounts; changes are currently disabled.</p>@endunless
    @if(session('portal.notice'))<p role="status" class="portal-card border rounded-xl p-4">{{ session('portal.notice') }}</p>@endif
    @if($errors->any())<p role="alert" class="text-[#b56f2f]">{{ $errors->first() }}</p>@endif

    <section class="portal-card w-full rounded-xl border p-6 lg:p-8 space-y-6" aria-labelledby="account-details-title">
        <h2 id="account-details-title" class="portal-heading text-2xl">Account details</h2>
        <dl class="portal-copy grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach(['UserCreateDate' => 'Created', 'UserLastModifiedDate' => 'Last updated', 'PreferredMfaSetting' => 'Preferred MFA', 'UserMFASettingList' => 'MFA methods'] as $key => $label)
                @if(isset($selected[$key]))<div class="min-w-0"><dt class="font-semibold">{{ $label }}</dt><dd class="mt-1 break-words">{{ is_array($selected[$key]) ? implode(', ', $selected[$key]) : $selected[$key] }}</dd></div>@endif
            @endforeach
            @foreach($attributes as $name=>$value)<div class="min-w-0 {{ strlen($value) > 100 ? 'sm:col-span-2 lg:col-span-3' : '' }}"><dt class="font-semibold">{{ $name }}</dt><dd class="mt-1 break-all whitespace-pre-wrap">{{ $value }}</dd></div>@endforeach
        </dl>
    </section>

    <section class="portal-card w-full rounded-xl border p-6 lg:p-8" aria-labelledby="manage-account-title">
        <form method="POST" action="{{ route('portal.admin.users.attributes') }}" class="mb-8 grid gap-4 md:grid-cols-2">@csrf<input type="hidden" name="username" value="{{ $selected['Username'] }}"><h2 class="portal-heading text-2xl md:col-span-2">Edit attributes</h2>
            @foreach(['email'=>'Email','given_name'=>'First name','family_name'=>'Last name','phone_number'=>'Phone number'] as $name=>$label)<label class="portal-label">{{ $label }}<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="{{ $name }}" value="{{ $attributes[$name] ?? '' }}"></label>@endforeach
            @include('portal.partials.role-picker', ['roleValue' => $attributes['custom:user_role'] ?? ''])<button class="rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white" @disabled(!$writesEnabled)>Save attributes</button></form>
        <form method="POST" action="{{ route('portal.admin.users.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="username" value="{{ $selected['Username'] }}">
            <input type="hidden" name="user_subject" value="{{ $attributes['sub'] ?? '' }}">
            @foreach($listContext as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <fieldset @disabled(!$writesEnabled) class="space-y-5 disabled:opacity-50">
                <legend id="manage-account-title" class="portal-heading text-2xl mb-5">Manage account</legend>
                <label class="portal-label block max-w-xl">Action
                    <select class="portal-input w-full mt-2 rounded-xl border px-4 py-3" name="action" id="admin-action">
                        @foreach(['confirm' => 'Confirm account', 'reset_password' => 'Send password reset', 'set_password' => 'Set password', 'enable' => 'Enable account', 'disable' => 'Disable account', 'sign_out' => 'Sign out all sessions', 'delete' => 'Delete user'] as $action => $label)
                            <option value="{{ $action }}" @selected($currentAction === $action) @disabled($action === 'delete' && $isOwnAccount)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if($isOwnAccount)<p class="portal-copy text-sm">You cannot delete your own account.</p>@endif
                <div id="admin-password-fields" @if($currentAction !== 'set_password') hidden @endif class="max-w-xl space-y-4">
                    <label class="portal-label block">New password<input class="portal-input w-full mt-2 rounded-xl border px-4 py-3" type="password" name="password" autocomplete="new-password" @disabled($currentAction !== 'set_password') @required($currentAction === 'set_password')></label>
                    <label class="portal-label block">Confirm password<input class="portal-input w-full mt-2 rounded-xl border px-4 py-3" type="password" name="password_confirmation" autocomplete="new-password" @disabled($currentAction !== 'set_password') @required($currentAction === 'set_password')></label>
                    <label class="portal-copy flex gap-2"><input type="checkbox" name="temporary" value="1" @checked(old('temporary')) @disabled($currentAction !== 'set_password')>Require a password change at next sign-in</label>
                </div>
                <div id="admin-delete-fields" @if($currentAction !== 'delete') hidden @endif class="space-y-4 rounded-xl border border-[#edd7b5] bg-[#fff7e8] p-5 text-[#8a3030]">
                    <h3 class="text-lg font-semibold">Permanently delete this user</h3>
                    <p>This permanently removes the sign-in account from the shared user pool and cannot be undone. Records held by the other applications remain.</p>
                    <label class="block max-w-xl">Type <strong>{{ $selected['Username'] }}</strong> to confirm
                        <input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" type="text" name="delete_confirmation" autocomplete="off" spellcheck="false" maxlength="128" @disabled($currentAction !== 'delete') @required($currentAction === 'delete')>
                    </label>
                </div>
                <label class="portal-copy flex gap-2"><input type="checkbox" name="confirm_action" value="1" required>I confirm this action for {{ $selected['Username'] }}.</label>
                <button id="admin-apply-action" class="rounded-xl px-6 py-3 font-semibold text-white {{ $currentAction === 'delete' ? 'bg-[#b53c3c]' : 'bg-[#3da7c7]' }}">{{ $currentAction === 'delete' ? 'Delete user' : 'Apply action' }}</button>
            </fieldset>
        </form>
    </section>
</main>
<script>
(() => {
    const action = document.getElementById('admin-action');
    const passwordFields = document.getElementById('admin-password-fields');
    const deleteFields = document.getElementById('admin-delete-fields');
    const button = document.getElementById('admin-apply-action');
    action.addEventListener('change', () => {
        passwordFields.hidden = action.value !== 'set_password';
        passwordFields.querySelectorAll('input').forEach(input => {
            input.disabled = passwordFields.hidden;
            input.required = !passwordFields.hidden && input.type === 'password';
            if (passwordFields.hidden) { if (input.type === 'checkbox') input.checked = false; else input.value = ''; }
        });
        deleteFields.hidden = action.value !== 'delete';
        const confirmation = deleteFields.querySelector('input');
        confirmation.disabled = deleteFields.hidden;
        confirmation.required = !deleteFields.hidden;
        confirmation.value = '';
        action.form.elements.confirm_action.checked = false;
        button.textContent = deleteFields.hidden ? 'Apply action' : 'Delete user';
        button.classList.toggle('bg-[#b53c3c]', !deleteFields.hidden);
        button.classList.toggle('bg-[#3da7c7]', deleteFields.hidden);
    });
})();
</script>
@endsection
