<?php

namespace App\Http\Controllers;

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

        return response()->view('portal.email-tracking', [
            'authStatus' => $request->session()->get('auth.status'),
            'days' => $days,
            'start' => $start,
            'end' => $end,
            'metrics' => $metrics->report($start, $end),
        ])->header('Cache-Control', 'no-store, private');
    }
}
