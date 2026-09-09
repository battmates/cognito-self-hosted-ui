@extends('layouts.app')
@section('content')
<main class="portal-shell mx-auto w-full max-w-3xl space-y-6 px-5 py-8 lg:px-10">
    <a class="font-semibold text-[#3da7c7]" href="{{ route('portal.home') }}">← Your applications</a>
    <div><h1 class="portal-title text-4xl">Edit profile</h1><p class="portal-copy mt-3">Update your name or change your password. Your email address, access role, and other account settings are managed separately.</p></div>
    @if(session('portal.notice'))<p role="status" class="portal-card rounded-xl border p-4">{{ session('portal.notice') }}</p>@endif
    @if($errors->any())<p role="alert" class="text-[#b56f2f]">{{ $errors->first() }}</p>@endif
    <section class="portal-card rounded-xl border p-6"><h2 class="portal-heading text-2xl">Your name</h2><form method="POST" action="{{ route('portal.profile.update') }}" class="mt-5 grid gap-4 md:grid-cols-2">@csrf @method('PUT')
        <label class="portal-label">First name<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="given_name" required maxlength="128" value="{{ old('given_name', $authStatus['user']['first_name'] ?? '') }}"></label>
        <label class="portal-label">Last name<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="family_name" required maxlength="128" value="{{ old('family_name', $authStatus['user']['last_name'] ?? '') }}"></label>
        <div class="flex justify-end md:col-span-2"><button class="rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white">Save name</button></div>
    </form></section>
    <section class="portal-card rounded-xl border p-6"><h2 class="portal-heading text-2xl">Change password</h2><form method="POST" action="{{ route('portal.profile.password') }}" class="mt-5 grid gap-4">@csrf @method('PUT')
        <label class="portal-label">Current password<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="portal-label">New password<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" type="password" name="password" required autocomplete="new-password"></label>
        <label class="portal-label">Confirm new password<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <div class="flex justify-end"><button class="rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white">Change password</button></div>
    </form></section>
</main>
@endsection
