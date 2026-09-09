<?php

namespace App\Http\Controllers;

use App\Models\SesEmailEvent;
use App\Services\SesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailTrackingController extends Controller
{
    public function index(Request $request, SesMetrics $metrics)
    {
        $input = $request->validate(['days' => ['nullable', 'integer', Rule::in([7, 14, 30, 60])]]);
        $days = (int) ($input['days'] ?? 7);
        $end = CarbonImmutable::now('UTC')->addDay()->startOfDay();
        $start = $end->subDays($days);

        $report = $metrics->report($start, $end);

        return response()->view('portal.email-tracking', [
            'authStatus' => $request->session()->get('auth.status'),
            'days' => $days,
            'start' => $start,
            'end' => $end,
            'metrics' => $report,
            'eventTypes' => ['Send', 'Delivery', 'DeliveryDelay', 'Bounce', 'Complaint', 'Reject', 'Rendering Failure'],
            'chartData' => ['days' => $report['days'], 'volume' => $report['volume'], 'rates' => $report['rates'],
                'eventTopicReady' => (bool) config('ses_reporting.sns_topic_arn')],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function events(Request $request)
    {
        $input = $request->validate([
            'draw' => ['nullable', 'integer', 'min:0', 'max:100000'], 'start' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'length' => ['nullable', 'integer', 'min:1', 'max:100'], 'search.value' => ['nullable', 'string', 'max:128'],
            'event_type' => ['nullable', 'string', 'max:40'], 'order.0.column' => ['nullable', 'integer', 'min:0', 'max:4'],
            'order.0.dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = SesEmailEvent::query();
        $total = (clone $query)->count();
        $search = trim((string) data_get($input, 'search.value', ''));
        if ($search !== '') {
            $escaped = addcslashes(strtolower($search), '%_\\');
            $query->where(fn ($q) => $q->whereRaw('LOWER(recipient) LIKE ?', ["%{$escaped}%"])
                ->orWhereRaw('LOWER(ses_message_id) LIKE ?', ["%{$escaped}%"])->orWhereRaw('LOWER(event_type) LIKE ?', ["%{$escaped}%"]));
        }
        if (($input['event_type'] ?? '') !== '') {
            $query->where('event_type', $input['event_type']);
        }
        $filtered = (clone $query)->count();
        $columns = ['recipient', 'subject', 'event_type', 'occurred_at'];
        $column = $columns[(int) data_get($input, 'order.0.column', 4)] ?? 'occurred_at';
        $rows = $query->orderBy($column, data_get($input, 'order.0.dir', 'desc'))->orderByDesc('id')
            ->offset((int) ($input['start'] ?? 0))->limit((int) ($input['length'] ?? 25))->get();

        return response()->json([
            'draw' => (int) ($input['draw'] ?? 0), 'recordsTotal' => $total, 'recordsFiltered' => $filtered,
            'hasMore' => ((int) ($input['start'] ?? 0) + $rows->count()) < $filtered,
            'data' => $rows->map(fn (SesEmailEvent $event) => [e($event->recipient), e($event->subject ?? '—'), $event->event_type,
                $event->occurred_at->format('j M Y, H:i:s').' UTC', $event->occurred_at->toIso8601String(), $event->detail ?? '—',
                $event->source ?? '—', $event->ses_message_id, $event->sns_message_id])->all(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
