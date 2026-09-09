@php
    $selectedRole = old('role', $roleValue ?? '');
    $isNewRole = $selectedRole === '__new__';
@endphp
<div class="space-y-3" data-role-picker data-roles-url="{{ route('portal.admin.roles') }}">
    <label class="portal-label block">Role
        <select class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="role" data-role-select>
            <option value="" @selected($selectedRole === '')>No role</option>
            @if($selectedRole !== '' && $selectedRole !== '__new__')<option value="{{ $selectedRole }}" selected>{{ $selectedRole }}</option>@endif
            <option value="__new__" @selected($isNewRole)>Add a new role…</option>
        </select>
    </label>
    <label class="portal-label block" data-new-role-field @if(!$isNewRole) hidden @endif>New role
        <input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="new_role" value="{{ old('new_role') }}" maxlength="128" @disabled(!$isNewRole) data-new-role-input>
    </label>
    <p class="portal-copy text-sm" data-role-status>Loading roles already used in the user pool…</p>
</div>
<script>
(() => {
    const picker = document.currentScript.previousElementSibling;
    const select = picker.querySelector('[data-role-select]');
    const newRole = picker.querySelector('[data-new-role-field]');
    const newRoleInput = picker.querySelector('[data-new-role-input]');
    const status = picker.querySelector('[data-role-status]');
    const selected = select.value;
    const setNewRoleVisibility = () => {
        const isNew = select.value === '__new__';
        newRole.hidden = !isNew;
        newRoleInput.disabled = !isNew;
        if (isNew) newRoleInput.focus();
    };
    select.addEventListener('change', setNewRoleVisibility);
    fetch(picker.dataset.rolesUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
        .then(response => response.ok ? response.json() : Promise.reject())
        .then(data => {
            if (!Array.isArray(data.roles) || !data.roles.every(role => typeof role === 'string')) throw new Error();
            const roles = [...new Set(data.roles.filter(role => role.trim() !== ''))].sort((a, b) => a.localeCompare(b));
            if (selected !== '' && selected !== '__new__' && !roles.includes(selected)) roles.push(selected);
            select.replaceChildren(new Option('No role', ''), ...roles.map(role => new Option(role, role)), new Option('Add a new role…', '__new__'));
            select.value = selected;
            status.textContent = roles.length ? 'Choose a role already in use, or add a new one.' : 'No existing roles found. Add a new role if needed.';
            setNewRoleVisibility();
        })
        .catch(() => { status.textContent = 'Existing roles could not be loaded. You can still add a new role.'; });
})();
</script>
