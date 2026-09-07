@php($challenge = session('portal.challenge'))
<form method="POST" action="{{ route('portal.challenge.store') }}" class="space-y-4">
    @csrf
                            @include('portal.partials.context-fields', ['portalContext' => $portalContext])
    <h1 class="portal-heading text-2xl">{{ $challenge['name'] === 'NEW_PASSWORD_REQUIRED' ? 'Choose your password' : 'Verify your sign-in' }}</h1>
    @if ($errors->any())<p role="alert" class="text-[#b56f2f]">{{ $errors->first() }}</p>@endif
    @if ($challenge['name'] === 'NEW_PASSWORD_REQUIRED')
        @foreach ($challenge['required_attributes'] ?? [] as $attribute)
            <label class="portal-label block">{{ ucwords(str_replace('_', ' ', $attribute)) }}
                <input class="portal-input mt-2 w-full rounded-xl border px-4 py-3" name="{{ $attribute }}" value="{{ old($attribute) }}" required>
            </label>
        @endforeach
        <label class="portal-label block">New password<input class="portal-input mt-2 w-full rounded-xl border px-4 py-3" name="password" type="password" autocomplete="new-password" required></label>
        <label class="portal-label block">Confirm password<input class="portal-input mt-2 w-full rounded-xl border px-4 py-3" name="password_confirmation" type="password" autocomplete="new-password" required></label>
    @else
        <p class="portal-copy">{{ $challenge['name'] === 'SOFTWARE_TOKEN_MFA' ? 'Enter the code from your authenticator app.' : 'Enter the verification code sent to you.' }}</p>
        <label class="portal-label block">Verification code<input class="portal-input mt-2 w-full rounded-xl border px-4 py-3" name="code" autocomplete="one-time-code" inputmode="numeric" required autofocus></label>
    @endif
    <button class="w-full rounded-xl bg-[#3da7c7] px-5 py-3 font-semibold text-white">Continue</button>
</form>
