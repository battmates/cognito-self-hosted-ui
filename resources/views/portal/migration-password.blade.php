@extends('layouts.app')
@section('content')
    <main class="w-full px-4 py-16">
        <section class="portal-card password-setup-card mx-auto rounded-3xl border shadow-xl">
            @include('portal.partials.migration-password-form', ['standalone' => true])
        </section>
    </main>
@endsection
@vite('resources/js/app.js')
