@foreach($users as $user)
    @php
        $attrs = array_column($user['Attributes'] ?? [], 'Value', 'Name');
        $emailVerified = ($attrs['email_verified'] ?? 'false') === 'true';
        [$confirmationLabel, $confirmationStyle] = match ($user['UserStatus'] ?? 'UNKNOWN') {
            'CONFIRMED' => ['Confirmed', 'success'],
            'FORCE_CHANGE_PASSWORD' => ['Force change password', 'warning'],
            'UNCONFIRMED' => ['Unconfirmed', 'warning'],
            'RESET_REQUIRED' => ['Password reset required', 'warning'],
            'EXTERNAL_PROVIDER' => ['External provider', 'info'],
            'COMPROMISED' => ['Compromised', 'danger'],
            'ARCHIVED' => ['Archived', 'neutral'],
            default => ['Unknown', 'neutral'],
        };
    @endphp
    <tr class="border-b portal-divider" data-user-row="{{ $user['Username'] }}">
        <td class="py-4 pr-5"><a class="text-[#3da7c7] font-semibold break-all" href="{{ route('portal.admin.users', array_merge(request()->only('field', 'q'), ['username' => $user['Username']])) }}">{{ $user['Username'] }}</a></td>
        <td class="py-4 pr-5 text-sm break-all">{{ $attrs['email'] ?? 'No email' }}</td>
        <td class="py-4 pr-5"><span class="portal-status-badge portal-status-badge--{{ $emailVerified ? 'success' : 'neutral' }}">{{ $emailVerified ? 'Verified' : 'Not verified' }}</span></td>
        <td class="py-4 pr-5"><span class="portal-status-badge portal-status-badge--{{ $confirmationStyle }}">{{ $confirmationLabel }}</span></td>
        <td class="py-4"><span class="portal-status-badge portal-status-badge--{{ $user['Enabled'] ? 'success' : 'danger' }}">{{ $user['Enabled'] ? 'Enabled' : 'Disabled' }}</span></td>
        <td class="py-4 pl-3 text-right">
            <button type="button" class="portal-row-action-trigger inline-flex h-9 w-9 items-center justify-center rounded-lg border portal-divider" aria-label="Actions for {{ $user['Username'] }}" aria-controls="admin-row-menu" aria-expanded="false"
                data-user-menu data-username="{{ $user['Username'] }}" data-subject="{{ $attrs['sub'] ?? '' }}" data-own-account="{{ strcasecmp($user['Username'], (string) ($authStatus['user']['username'] ?? '')) === 0 ? 'true' : 'false' }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
            </button>
        </td>
    </tr>
@endforeach
