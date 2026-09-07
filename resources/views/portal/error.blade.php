@extends('layouts.app')
@section('content')
<main class="mx-auto w-full max-w-xl px-5 py-24">
    <section class="portal-card rounded-xl border p-8 space-y-5">
        <h1 class="portal-heading text-2xl">Unable to continue</h1>
        <p class="portal-copy">{{ $message }}</p>
        <a class="text-[#3da7c7] font-semibold" href="{{ route('portal.home') }}">Return to the portal</a>
    </section>
</main>
@endsection
