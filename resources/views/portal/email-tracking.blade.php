@extends('layouts.app')
@section('content')
<main class="portal-shell mx-auto w-full max-w-7xl space-y-6 px-5 py-8 lg:px-10">
    <a class="font-semibold text-[#3da7c7]" href="{{ route('portal.home') }}">← Your applications</a>
    <div class="flex flex-wrap items-end justify-between gap-5">
        <div>
            <h1 class="portal-title text-4xl">Email tracking</h1>
            <p class="portal-copy mt-3">Amazon SES deliverability metrics for Cognito email.</p>
        </div>
        <form method="GET" action="{{ route('portal.admin.email-tracking') }}">
            <label class="portal-label block">Date range
                <select name="days" class="portal-input mt-2 block rounded-xl border px-4 py-3" onchange="this.form.submit()">
                    @foreach([7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 60 => 'Last 60 days'] as $value => $label)
                        <option value="{{ $value }}" @selected($days === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    </div>

    <section class="portal-card rounded-xl border p-5" aria-label="Reporting scope">
        <dl class="portal-copy grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="font-semibold portal-heading">Source</dt><dd class="mt-1">{{ $metrics['source'] }}</dd></div>
            <div><dt class="font-semibold portal-heading">Scope</dt><dd class="mt-1">{{ $metrics['scope'] }}</dd></div>
            <div><dt class="font-semibold portal-heading">Period</dt><dd class="mt-1">{{ $start->format('j M Y') }} – {{ $end->subDay()->format('j M Y') }} UTC</dd></div>
            <div><dt class="font-semibold portal-heading">Updated</dt><dd class="mt-1">{{ \Carbon\CarbonImmutable::parse($metrics['updated_at'])->format('j M Y, H:i') }} UTC</dd></div>
        </dl>
    </section>

    @foreach($metrics['notices'] as $notice)
        <p class="rounded-xl border border-[#edd7b5] bg-[#fff7e8] p-4 text-[#8a6130]">{{ $notice }}</p>
    @endforeach

    @if($metrics['available'])
        <section class="grid gap-6 xl:grid-cols-2" aria-label="Email metrics">
            <article class="portal-card min-w-0 rounded-xl border p-5 lg:p-6">
                <h2 class="portal-heading text-2xl">Volume over time</h2>
                <p class="portal-copy mt-2 text-sm">Daily event totals reported by SES.</p>
                <div id="email-volume-chart" class="email-chart mt-5" role="img" aria-label="Daily email event volume chart"></div>
            </article>
            <article class="portal-card min-w-0 rounded-xl border p-5 lg:p-6">
                <h2 class="portal-heading text-2xl">Rate over time</h2>
                <p class="portal-copy mt-2 text-sm">Daily rates using the denominators defined by SES.</p>
                <div id="email-rate-chart" class="email-chart mt-5" role="img" aria-label="Daily email event rate chart"></div>
            </article>
        </section>
    @else
        <section class="portal-card rounded-xl border p-6">
            <h2 class="portal-heading text-2xl">Metrics unavailable</h2>
            <p class="portal-copy mt-3">No live SES metric points were returned for this period.</p>
        </section>
    @endif

    <section class="portal-card rounded-xl border p-6">
        <h2 class="portal-heading text-2xl">Message activity</h2>
        <p class="portal-copy mt-3">Incoming SES status events appear here after event publishing is activated. Each row is a status update for an email, so delivery and bounce history remains visible.</p>
        <div class="mt-5 overflow-x-auto" id="email-events-results"><table class="w-full" id="email-events-table" data-events-url="{{ route('portal.admin.email-events') }}"><thead><tr><th>Recipient</th><th>SES message ID</th><th>Status</th><th>Detail</th><th>Event time</th></tr></thead><tbody></tbody></table></div>
    </section>
</main>
<script id="email-tracking-data" type="application/json">@json($chartData)</script>
@vite('resources/js/email-tracking.js')
@endsection
