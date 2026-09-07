<div id="admin-row-menu" popover="auto" class="portal-card fixed m-0 w-60 max-h-[80vh] overflow-y-auto rounded-xl border p-2" aria-label="User actions">
    <p id="admin-row-menu-user" class="break-all px-3 py-2 text-sm font-semibold"></p>
    <a data-view-profile class="portal-row-menu-item" href="{{ route('portal.admin.users') }}">View profile</a>
    @foreach(['confirm' => 'Confirm account', 'reset_password' => 'Send password reset', 'set_password' => 'Set password', 'enable' => 'Enable account', 'disable' => 'Disable account', 'sign_out' => 'Sign out all sessions', 'delete' => 'Delete user'] as $action => $label)
        <button type="button" data-row-action="{{ $action }}" @disabled(!$writesEnabled) class="portal-row-menu-item {{ $action === 'delete' ? 'portal-row-menu-item--danger' : '' }}">{{ $label }}</button>
    @endforeach
</div>
<dialog id="admin-row-dialog" class="portal-dialog m-auto w-[calc(100%-2rem)] max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl border p-6" aria-labelledby="admin-row-dialog-title" aria-describedby="admin-row-dialog-description">
    <form method="POST" action="{{ route('portal.admin.users.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="return_to" value="list">
        @foreach(['username', 'user_subject', 'action', 'field', 'q'] as $name)<input type="hidden" name="{{ $name }}">@endforeach
        <h2 id="admin-row-dialog-title" class="portal-heading text-2xl"></h2>
        <p id="admin-row-dialog-user" class="break-all font-semibold"></p>
        <p id="admin-row-dialog-description" class="portal-copy"></p>
        <div data-row-password-fields hidden class="space-y-4">
            <label class="portal-label block">New password<input name="password" type="password" autocomplete="new-password" class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" disabled></label>
            <label class="portal-label block">Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" disabled></label>
            <label class="portal-copy flex gap-2"><input type="checkbox" name="temporary" value="1" disabled>Require a password change at next sign-in</label>
        </div>
        <label data-row-delete-fields hidden class="portal-label block">Type <strong data-delete-username class="break-all"></strong> to confirm
            <input name="delete_confirmation" autocomplete="off" spellcheck="false" maxlength="128" class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" disabled>
        </label>
        <label class="portal-copy flex gap-2"><input type="checkbox" name="confirm_action" value="1" required>I confirm this action for this account.</label>
        <div class="flex flex-wrap justify-end gap-3">
            <button type="button" data-cancel-row-action class="portal-secondary-button rounded-xl border px-5 py-3 font-semibold">Cancel</button>
            <button type="submit" data-submit-row-action @disabled(!$writesEnabled) class="rounded-xl bg-[#3da7c7] px-5 py-3 font-semibold text-white">Apply action</button>
        </div>
    </form>
</dialog>
<script>
(() => {
    const results = document.getElementById('admin-search-results');
    const search = document.getElementById('admin-search-form');
    const menu = document.getElementById('admin-row-menu');
    const dialog = document.getElementById('admin-row-dialog');
    const form = dialog.querySelector('form');
    const writesEnabled = @json((bool) $writesEnabled);
    let trigger;
    const descriptions = {
        confirm: 'Confirm this account so the user can sign in without completing sign-up confirmation.',
        reset_password: 'Send this user a password reset message using their registered recovery method.',
        set_password: 'Set a new password for this account.',
        enable: 'Enable sign-in for this account.',
        disable: 'Disable sign-in for this account. An administrator can enable it again later.',
        sign_out: 'Sign this user out of their Cognito sessions across applications.',
        delete: 'This permanently removes the sign-in account from the shared user pool and cannot be undone. Records held by the other applications remain.',
    };
    const hideMenu = () => {
        trigger?.setAttribute('aria-expanded', 'false');
        if (menu.matches(':popover-open')) menu.hidePopover();
    };
    ['input', 'change', 'submit'].forEach(event => search.addEventListener(event, hideMenu));
    menu.addEventListener('toggle', event => trigger?.setAttribute('aria-expanded', String(event.newState === 'open')));
    results.addEventListener('click', event => {
        const button = event.target.closest('[data-user-menu]');
        if (!button || results.inert) return;
        const wasOpen = menu.matches(':popover-open') && trigger === button;
        hideMenu();
        if (wasOpen) return;
        trigger = button;
        document.getElementById('admin-row-menu-user').textContent = button.dataset.username;
        const url = new URL(search.action);
        url.search = new URLSearchParams({field: search.elements.field.value, q: search.elements.q.value, username: button.dataset.username});
        menu.querySelector('[data-view-profile]').href = url;
        menu.querySelectorAll('[data-row-action]').forEach(action => {
            action.disabled = !writesEnabled || (button.dataset.ownAccount === 'true' && !['confirm', 'enable'].includes(action.dataset.rowAction));
        });
        menu.showPopover();
        const rect = button.getBoundingClientRect();
        menu.style.left = `${Math.max(8, Math.min(rect.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8))}px`;
        menu.style.top = `${Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - menu.offsetHeight - 8))}px`;
        menu.querySelector('[data-view-profile]').focus();
    });
    menu.addEventListener('click', event => {
        const button = event.target.closest('[data-row-action]');
        if (!button || button.disabled || !trigger?.isConnected) return;
        const action = button.dataset.rowAction;
        hideMenu();
        form.reset();
        form.elements.username.value = trigger.dataset.username;
        form.elements.user_subject.value = trigger.dataset.subject;
        form.elements.action.value = action;
        form.elements.field.value = search.elements.field.value;
        form.elements.q.value = search.elements.q.value;
        document.getElementById('admin-row-dialog-title').textContent = button.textContent;
        document.getElementById('admin-row-dialog-user').textContent = trigger.dataset.username;
        document.getElementById('admin-row-dialog-description').textContent = descriptions[action];
        dialog.querySelector('[data-delete-username]').textContent = trigger.dataset.username;
        const passwordFields = dialog.querySelector('[data-row-password-fields]');
        passwordFields.hidden = action !== 'set_password';
        passwordFields.querySelectorAll('input').forEach(input => {
            input.disabled = passwordFields.hidden;
            input.required = !passwordFields.hidden && input.type === 'password';
        });
        dialog.querySelector('[data-row-delete-fields]').hidden = action !== 'delete';
        form.elements.delete_confirmation.disabled = action !== 'delete';
        form.elements.delete_confirmation.required = action === 'delete';
        const submit = dialog.querySelector('[data-submit-row-action]');
        submit.textContent = button.textContent;
        submit.classList.toggle('bg-[#b53c3c]', ['delete', 'disable'].includes(action));
        submit.classList.toggle('bg-[#3da7c7]', !['delete', 'disable'].includes(action));
        dialog.showModal();
        dialog.querySelector('[data-cancel-row-action]').focus();
    });
    dialog.querySelector('[data-cancel-row-action]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { form.reset(); if (trigger?.isConnected) trigger.focus(); });
})();
</script>
