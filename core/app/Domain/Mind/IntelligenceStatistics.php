<?php

namespace App\Domain\Mind;

use App\Domain\Tenancy\TenantContext;
use App\Models\DetectionResult;
use App\Models\SecurityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the Threat Intelligence view, computed from stored analysis
 * results for the current tenant (last 24 hours unless stated).
 */
final class IntelligenceStatistics
{
    public const RISK_BUCKETS = 10;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $from = $now->subDay();
        $org = $this->tenant->organisationId();

        $analysed = SecurityEvent::query()->where('received_at', '>=', $from)->whereNotNull('analysed_at');

        $verdicts = (clone $analysed)->selectRaw('verdict, count(*) AS total')->groupBy('verdict')->pluck('total', 'verdict');
        $categories = (clone $analysed)->whereNotNull('threat_category')
            ->selectRaw('threat_category, count(*) AS total')->groupBy('threat_category')->orderByDesc('total')->pluck('total', 'threat_category');

        $histogram = DB::select(
            'SELECT b.bucket, count(e.id) AS total
             FROM generate_series(0, ? - 1) AS b(bucket)
             LEFT JOIN security_events e
               ON e.organisation_id = ? AND e.received_at >= ? AND e.risk_score IS NOT NULL
              AND least(floor(e.risk_score / (100.0 / ?)), ? - 1) = b.bucket
             GROUP BY b.bucket ORDER BY b.bucket',
            [self::RISK_BUCKETS, $org, $from, self::RISK_BUCKETS, self::RISK_BUCKETS],
        );

        $ruleHits = DetectionResult::query()
            ->where('created_at', '>=', $from)
            ->where('detector', DetectionResult::DETECTOR_RULE)
            ->selectRaw('rule_key, count(*) AS total, max(score) AS max_score')
            ->groupBy('rule_key')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['rule_key' => $r->rule_key, 'count' => (int) $r->total, 'max_score' => (float) $r->max_score])
            ->all();

        $anomalyTrend = DB::select(
            "WITH hourly AS (
                 SELECT date_trunc('hour', received_at) AS hour,
                        count(*) FILTER (WHERE anomaly_percentile >= 99) AS anomalous,
                        count(*) FILTER (WHERE verdict IN ('suspicious','malicious')) AS flagged,
                        count(*) AS analysed
                 FROM security_events
                 WHERE organisation_id = ? AND received_at >= date_trunc('hour', ?::timestamptz) AND analysed_at IS NOT NULL
                 GROUP BY 1
             )
             SELECT g.hour, coalesce(h.anomalous, 0) AS anomalous, coalesce(h.flagged, 0) AS flagged, coalesce(h.analysed, 0) AS analysed
             FROM generate_series(date_trunc('hour', ?::timestamptz), date_trunc('hour', ?::timestamptz), interval '1 hour') AS g(hour)
             LEFT JOIN hourly h ON h.hour = g.hour
             ORDER BY g.hour",
            [$org, $from, $from, $now],
        );

        $pending = SecurityEvent::query()->whereNull('analysed_at')->count();

        return [
            'generated_at' => $now->toIso8601String(),
            'analysed_last_24h' => array_sum($verdicts->all()),
            'awaiting_analysis' => $pending,
            'verdicts' => [
                'benign' => (int) ($verdicts['benign'] ?? 0),
                'suspicious' => (int) ($verdicts['suspicious'] ?? 0),
                'malicious' => (int) ($verdicts['malicious'] ?? 0),
            ],
            'categories' => collect($categories)->map(fn ($v, $k) => ['category' => $k, 'count' => (int) $v])->values()->all(),
            'risk_histogram' => array_map(fn ($r) => [
                'from' => (int) ($r->bucket * 100 / self::RISK_BUCKETS),
                'to' => (int) (($r->bucket + 1) * 100 / self::RISK_BUCKETS),
                'count' => (int) $r->total,
            ], $histogram),
            'rule_hits' => $ruleHits,
            'anomaly_trend' => array_map(fn ($r) => [
                'hour' => CarbonImmutable::parse($r->hour)->utc()->toIso8601String(),
                'anomalous' => (int) $r->anomalous,
                'flagged' => (int) $r->flagged,
                'analysed' => (int) $r->analysed,
            ], $anomalyTrend),
        ];
    }
}
