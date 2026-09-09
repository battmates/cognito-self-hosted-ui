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
        <div class="mt-5 flex flex-wrap gap-4">
        <label class="portal-label block min-w-52 max-w-xs">Status
            <select id="email-event-type" class="portal-input mt-2 block w-full rounded-xl border px-4 py-3"><option value="">All statuses</option>@foreach($eventTypes as $eventType)<option value="{{ $eventType }}">{{ $eventType }}</option>@endforeach</select>
        </label>
        <label class="portal-label block min-w-52 max-w-sm">Search
            <input id="email-event-search" type="search" class="portal-input mt-2 block w-full rounded-xl border px-4 py-3" placeholder="Email address, status, or message ID">
        </label>
        </div>
        <div class="mt-5" id="email-events-results"><table class="w-full" id="email-events-table" data-events-url="{{ route('portal.admin.email-events') }}"><thead><tr><th>Recipient</th><th>Subject</th><th>Status</th><th>Event time</th><th>Actions</th></tr></thead><tbody></tbody></table></div>
        <button id="email-events-load-more" type="button" class="mt-5 rounded-xl bg-[#3da7c7] px-6 py-3 font-semibold text-white" hidden>Load more</button>
    </section>
</main>
<dialog id="email-event-detail-dialog" class="portal-card max-w-2xl rounded-xl border p-0 text-left backdrop:bg-black/50">
    <div class="p-6"><div class="flex items-start justify-between gap-4"><h2 class="portal-heading text-2xl">Email event detail</h2><button type="button" data-close-email-detail class="portal-copy font-semibold">Close</button></div>
    <dl class="portal-copy mt-6 space-y-4"><div><dt class="portal-heading font-semibold">Recipient</dt><dd data-detail-recipient class="mt-1 break-all"></dd></div><div><dt class="portal-heading font-semibold">Subject</dt><dd data-detail-subject class="mt-1"></dd></div><div><dt class="portal-heading font-semibold">Status</dt><dd data-detail-status class="mt-1"></dd></div><div><dt class="portal-heading font-semibold">Event time</dt><dd data-detail-time class="mt-1"></dd></div><div><dt class="portal-heading font-semibold">From</dt><dd data-detail-source class="mt-1 break-all"></dd></div><div><dt class="portal-heading font-semibold">SES message ID</dt><dd data-detail-ses-message-id class="mt-1 break-all font-mono text-xs"></dd></div><div><dt class="portal-heading font-semibold">SNS event ID</dt><dd data-detail-sns-message-id class="mt-1 break-all font-mono text-xs"></dd></div><div><dt class="portal-heading font-semibold">Detail</dt><dd data-detail-message class="mt-1 whitespace-pre-wrap break-words"></dd></div></dl></div>
</dialog>
<script id="email-tracking-data" type="application/json">@json($chartData)</script>
@vite('resources/js/email-tracking.js')
@endsection
