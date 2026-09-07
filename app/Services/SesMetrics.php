<?php

namespace App\Services;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SesMetrics
{
    public const METRICS = [
        'sent' => ['Sent', 'SEND'], 'delivered' => ['Delivered', 'DELIVERY'],
        'complaints' => ['Complaints', 'COMPLAINT'], 'transient' => ['Transient bounces', 'TRANSIENT_BOUNCE'],
        'permanent' => ['Permanent bounces', 'PERMANENT_BOUNCE'], 'opens' => ['Opens', 'OPEN'], 'clicks' => ['Clicks', 'CLICK'],
    ];

    public function __construct(private SesV2Client $ses, private CloudWatchClient $cloudWatch) {}

    public function report(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = 'ses-metrics:'.hash('sha256', json_encode([config('ses_reporting'), $start->toIso8601String(), $end->toIso8601String()]));

        return Cache::remember($key, config('ses_reporting.cache_seconds'), fn () => $this->fetch($start, $end));
    }

    private function fetch(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }
        $queries = [];
        $metrics = array_map(fn ($metric) => $metric[1], self::METRICS);
        $metrics += ['open_base' => 'DELIVERY_OPEN', 'click_base' => 'DELIVERY_CLICK', 'complaint_base' => 'DELIVERY_COMPLAINT'];
        foreach ($metrics as $id => $metric) {
            $query = ['Id' => $id, 'Namespace' => 'VDM', 'Metric' => $metric, 'StartDate' => $start->timestamp, 'EndDate' => $end->timestamp];
            if (config('ses_reporting.configuration_set')) {
                $query['Dimensions'] = ['CONFIGURATION_SET' => config('ses_reporting.configuration_set')];
            }
            $queries[] = $query;
        }
        $notices = $this->accountNotices();
        try {
            $result = $this->ses->batchGetMetricData(['Queries' => $queries])->toArray();
            $values = $this->values($result['Results'] ?? [], $days);
            $source = 'SES Virtual Deliverability Manager';
            $scope = config('ses_reporting.configuration_set') ? 'Configuration set: '.config('ses_reporting.configuration_set') : 'All SES mail in '.config('ses_reporting.region');
            if (! empty($result['Errors'])) {
                $notices[] = 'AWS could not return some metrics. Unavailable values are shown as gaps.';
            }
            if (! $values) {
                $notices[] = 'VDM returned no metric data for these dates. Reporting may not be enabled for this period.';
            }
        } catch (AwsException $e) {
            $notices[] = $this->awsNotice($e, 'SES deliverability metrics');
            $result = $this->fallback($start, $end);
            $values = $this->values($result['results'], $days);
            $source = 'CloudWatch SES metrics';
            $scope = 'All SES mail in '.config('ses_reporting.region').' (account-wide, not just Cognito)';
            $notices = array_merge($notices, $result['notices']);
            $notices[] = 'CloudWatch does not separate transient and permanent bounces here. Available combined bounces are shown separately. Engagement and complaint rates use all deliveries, so they differ from VDM rates.';
        }
        $vdm = $source === 'SES Virtual Deliverability Manager';
        $definitions = self::METRICS;
        if (isset($values['bounces'])) {
            $definitions['bounces'] = ['Bounces (unsplit)', 'BOUNCE'];
        }
        $volume = $rates = [];
        foreach ($definitions as $id => [$label]) {
            $points = $values[$id] ?? array_fill_keys($days, null);
            $denominator = match ($id) {
                'opens' => $vdm ? 'open_base' : 'delivered',
                'clicks' => $vdm ? 'click_base' : 'delivered',
                'complaints' => $vdm ? 'complaint_base' : 'delivered',
                default => 'sent',
            };
            $ratePoints = [];
            foreach ($days as $day) {
                $base = $values[$denominator][$day] ?? null;
                $point = $points[$day] ?? null;
                $ratePoints[] = $base !== null && $base > 0 && $point !== null ? round(100 * $point / $base, 3) : null;
            }
            $volume[] = ['name' => $label, 'data' => array_values($points)];
            $rates[] = ['name' => $label, 'data' => $ratePoints];
        }

        return ['days' => $days, 'volume' => $volume, 'rates' => $rates, 'source' => $source, 'scope' => $scope,
            'notices' => $notices, 'available' => collect($volume)->contains(fn ($series) => collect($series['data'])->contains(fn ($v) => $v !== null)),
            'updated_at' => now('UTC')->toIso8601String()];
    }

    private function accountNotices(): array
    {
        try {
            $account = $this->ses->getAccount()->toArray();
            $engagement = data_get($account, 'VdmAttributes.DashboardAttributes.EngagementMetrics');

            return $engagement === 'ENABLED'
                ? []
                : ['SES engagement tracking is disabled. Open and click metrics will be unavailable until it is enabled.'];
        } catch (AwsException $e) {
            return [$this->awsNotice($e, 'SES account reporting settings')];
        }
    }

    private function fallback(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $queries = [];
        foreach (['sent' => 'Send', 'delivered' => 'Delivery', 'complaints' => 'Complaint', 'bounces' => 'Bounce', 'opens' => 'Open', 'clicks' => 'Click'] as $id => $name) {
            $queries[] = ['Id' => $id, 'MetricStat' => ['Metric' => ['Namespace' => 'AWS/SES', 'MetricName' => $name], 'Period' => 86400, 'Stat' => 'Sum']];
        }
        try {
            $response = $this->cloudWatch->getMetricData(['StartTime' => $start->timestamp, 'EndTime' => $end->timestamp,
                'ScanBy' => 'TimestampAscending', 'MetricDataQueries' => $queries])->toArray();
            $results = array_values(array_filter($response['MetricDataResults'] ?? [], fn ($r) => ($r['StatusCode'] ?? '') === 'Complete'));
            $notices = count($results) !== count($queries) || isset($response['NextToken']) ? ['Some CloudWatch metrics are incomplete; only complete series are shown.'] : [];

            return ['results' => $results, 'notices' => $notices];
        } catch (AwsException $e) {
            return ['results' => [], 'notices' => [$this->awsNotice($e, 'CloudWatch metrics')]];
        }
    }

    private function values(array $results, array $days): array
    {
        $values = [];
        foreach ($results as $result) {
            $id = $result['Id'];
            $values[$id] = array_fill_keys($days, null);
            foreach ($result['Timestamps'] ?? [] as $index => $timestamp) {
                $date = is_numeric($timestamp) ? CarbonImmutable::createFromTimestampUTC($timestamp) : CarbonImmutable::parse($timestamp)->utc();
                $day = $date->format('Y-m-d');
                $value = $result['Values'][$index] ?? null;
                if (array_key_exists($day, $values[$id]) && is_numeric($value)) {
                    $values[$id][$day] = ($values[$id][$day] ?? 0) + (float) $value;
                }
            }
        }

        return $values;
    }

    private function awsNotice(AwsException $e, string $source): string
    {
        return match ($e->getAwsErrorCode()) {
            'AccessDenied', 'AccessDeniedException' => $source.': AWS denied reporting access. Read-only reporting permissions are needed.',
            'BadRequestException', 'NotFoundException' => $source.': reporting is not available for the configured source or dates.',
            default => $source.': AWS could not return reporting data. Please try again later.',
        };
    }
}
