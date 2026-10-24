<?php

namespace App\Domain\Eye;

use App\Domain\Tenancy\TenantContext;
use App\Models\MonitoredAsset;
use App\Models\SecurityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the security overview. Every figure is computed from the
 * tenant's stored data at request time; nothing is estimated or seeded.
 */
final class OverviewStatistics
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $organisationId = $this->tenant->organisationId();
        $dayAgo = $now->subDay();
        $hourAgo = $now->subHour();

        $severity24h = SecurityEvent::query()
            ->where('received_at', '>=', $dayAgo)
            ->selectRaw('severity, count(*) AS total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $statusCounts = SecurityEvent::query()
            ->selectRaw('processing_status, count(*) AS total')
            ->groupBy('processing_status')
            ->pluck('total', 'processing_status');

        return [
            'generated_at' => $now->toIso8601String(),
            'assets' => [
                'total' => MonitoredAsset::query()->count(),
                'by_criticality' => $this->fill(MonitoredAsset::CRITICALITIES, MonitoredAsset::query()
                    ->selectRaw('criticality, count(*) AS total')->groupBy('criticality')->pluck('total', 'criticality')),
                'seen_last_24h' => MonitoredAsset::query()->where('last_seen_at', '>=', $dayAgo)->count(),
            ],
            'events' => [
                'total' => array_sum($statusCounts->all()),
                'last_24h' => array_sum($severity24h->all()),
                'last_24h_by_severity' => $this->fill(Severity::values(), $severity24h),
                'high_or_critical_last_24h' => (int) (($severity24h['high'] ?? 0) + ($severity24h['critical'] ?? 0)),
                'by_status' => $this->fill(ProcessingStatus::values(), $statusCounts),
            ],
            'throughput' => $this->throughput($organisationId, $hourAgo, $now),
            'timeline' => $this->timeline($organisationId, $dayAgo, $now),
            'top_event_types' => SecurityEvent::query()
                ->where('received_at', '>=', $dayAgo)
                ->selectRaw('event_type, count(*) AS total')
                ->groupBy('event_type')
                ->orderByDesc('total')
                ->limit(8)
                ->get()
                ->map(fn ($row) => ['event_type' => $row->event_type, 'count' => (int) $row->total])
                ->all(),
            // KRATOS GUARD (Phase 4) supplies incident figures.
            'incidents' => null,
        ];
    }

    /**
     * Events received and processed per minute over the last hour, with
     * empty minutes filled in as zero.
     *
     * @return array{per_minute: list<array{minute: string, received: int, processed: int}>, received_last_minute: int, processed_last_minute: int, average_processing_ms: float|null}
     */
    private function throughput(string $organisationId, CarbonImmutable $from, CarbonImmutable $now): array
    {
        // Both aggregates are driven by the (organisation_id, received_at)
        // index. Events are processed shortly after receipt, so bounding
        // processed rows by received_at >= from - 1h loses nothing visible.
        $rows = DB::select(
            "WITH received AS (
                 SELECT date_trunc('minute', received_at) AS minute, count(*) AS total
                 FROM security_events
                 WHERE organisation_id = ? AND received_at >= date_trunc('minute', ?::timestamptz)
                 GROUP BY 1
             ), processed AS (
                 SELECT date_trunc('minute', processed_at) AS minute, count(*) AS total
                 FROM security_events
                 WHERE organisation_id = ?
                   AND received_at >= ?::timestamptz - interval '1 hour'
                   AND processed_at >= date_trunc('minute', ?::timestamptz)
                 GROUP BY 1
             )
             SELECT g.minute, coalesce(r.total, 0) AS received, coalesce(p.total, 0) AS processed
             FROM generate_series(date_trunc('minute', ?::timestamptz), date_trunc('minute', ?::timestamptz), interval '1 minute') AS g(minute)
             LEFT JOIN received r ON r.minute = g.minute
             LEFT JOIN processed p ON p.minute = g.minute
             ORDER BY g.minute",
            [$organisationId, $from, $organisationId, $from, $from, $from, $now],
        );

        $series = array_map(fn ($r) => [
            'minute' => CarbonImmutable::parse($r->minute)->utc()->toIso8601String(),
            'received' => (int) $r->received,
            'processed' => (int) $r->processed,
        ], $rows);

        $latency = SecurityEvent::query()
            ->where('processed_at', '>=', $from)
            ->selectRaw('avg(extract(epoch FROM (processed_at - received_at)) * 1000) AS avg_ms')
            ->value('avg_ms');

        $previous = $series[count($series) - 2] ?? ['received' => 0, 'processed' => 0];

        return [
            'per_minute' => $series,
            // The last complete minute; the current minute is still filling.
            'received_last_minute' => $previous['received'],
            'processed_last_minute' => $previous['processed'],
            'average_processing_ms' => $latency === null ? null : round((float) $latency, 1),
        ];
    }

    /**
     * Hourly event counts by severity over the last 24 hours.
     *
     * @return list<array<string, int|string>>
     */
    private function timeline(string $organisationId, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $rows = DB::select(
            "WITH counts AS (
                 SELECT date_trunc('hour', received_at) AS hour, severity, count(*) AS total
                 FROM security_events
                 WHERE organisation_id = ? AND received_at >= date_trunc('hour', ?::timestamptz)
                 GROUP BY 1, 2
             )
             SELECT g.hour, c.severity, c.total
             FROM generate_series(date_trunc('hour', ?::timestamptz), date_trunc('hour', ?::timestamptz), interval '1 hour') AS g(hour)
             LEFT JOIN counts c ON c.hour = g.hour
             ORDER BY g.hour",
            [$organisationId, $from, $from, $now],
        );

        $buckets = [];
        foreach ($rows as $row) {
            $hour = CarbonImmutable::parse($row->hour)->utc()->toIso8601String();
            $buckets[$hour] ??= ['hour' => $hour] + array_fill_keys(Severity::values(), 0);
            if ($row->severity !== null) {
                $buckets[$hour][$row->severity] = (int) $row->total;
            }
        }

        return array_values($buckets);
    }

    /**
     * @param  list<string>  $keys
     * @param  Collection<string, mixed>  $counts
     * @return array<string, int>
     */
    private function fill(array $keys, $counts): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = (int) ($counts[$key] ?? 0);
        }

        return $result;
    }
}
