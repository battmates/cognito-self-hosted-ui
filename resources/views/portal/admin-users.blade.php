@extends('layouts.app')
@section('content')
<main class="portal-shell mx-auto w-full max-w-7xl space-y-6 px-5 py-8 lg:px-10">
    <a class="font-semibold text-[#3da7c7]" href="{{ route('portal.home') }}">← Your applications</a>
    <div><h1 class="portal-title text-4xl">User management</h1><p class="portal-copy mt-3">Search by any part of an email address (for example, @domain.com), or the beginning of a username or name, then select an account.</p><p class="portal-copy mt-2 text-sm">Click column headings to sort the loaded accounts.</p></div>
    @unless($writesEnabled)<p class="rounded-xl border border-[#edd7b5] bg-[#fff7e8] p-4 text-[#8a6130]">Read-only mode. You can inspect accounts; changes are currently disabled.</p>@endunless
    @if(session('portal.notice'))<p role="status" class="portal-card border rounded-xl p-4">{{ session('portal.notice') }}</p>@endif
    @if($errors->any())<p role="alert" class="text-[#b56f2f]">{{ $errors->first() }}</p>@endif
    <section class="portal-card rounded-xl border p-6"><h2 class="portal-heading text-2xl">Create user</h2><form method="POST" action="{{ route('portal.admin.users.create') }}" class="mt-5 grid gap-4 md:grid-cols-2">@csrf
        @foreach(['username'=>'Username','email'=>'Email','given_name'=>'First name','family_name'=>'Last name','phone_number'=>'Phone number'] as $name=>$label)<label class="portal-label">{{ $label }}<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="{{ $name }}" @if($name==='email') type="email" @endif></label>@endforeach
        <label class="portal-label">Role<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" name="role" list="role-options"><datalist id="role-options"><option value="ops_manager"><option value="admin"><option value="administrator"></datalist></label>
        <label class="portal-label">Password<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" type="password" name="password"></label><label class="portal-label">Confirm password<input class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" type="password" name="password_confirmation"></label>
        <label class="portal-copy flex gap-2 md:col-span-2"><input type="checkbox" name="temporary" value="1">Require a password change at next sign-in</label><button class="rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white md:col-span-2" @disabled(!$writesEnabled)>Create user</button></form></section>
    <form id="admin-search-form" method="GET" action="{{ route('portal.admin.users') }}" class="flex flex-wrap gap-3">
        <label class="portal-label">Search field<select name="field" class="portal-input block mt-2 rounded-xl border px-4 py-3">@foreach(['email'=>'Email', 'username'=>'Username', 'given_name'=>'First name', 'family_name'=>'Last name', 'sub'=>'Subject ID'] as $field=>$label)<option value="{{ $field }}" @selected(request('field', 'email') === $field)>{{ $label }}</option>@endforeach</select></label>
        <label class="portal-label flex-1">Search<input class="portal-input block w-full mt-2 rounded-xl border px-4 py-3" name="q" maxlength="128" autocomplete="off" aria-controls="admin-search-results" value="{{ request('q') }}" placeholder="Leave blank to list all users"></label>
        <button class="self-end rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white">Search</button>
    </form>
    <p id="admin-search-status" role="status" aria-live="polite" class="portal-copy text-sm"></p>
    <div id="admin-search-results" aria-busy="false">
        @include('portal.partials.admin-results')
    </div>
    @include('portal.partials.admin-row-actions')
</main>
@vite('resources/js/admin-users.js')
@endsection
