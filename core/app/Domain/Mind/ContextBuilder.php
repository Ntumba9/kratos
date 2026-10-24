<?php

namespace App\Domain\Mind;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;

/**
 * Computes the history MIND needs to score one event (ADR-0007), in a single
 * query scoped to the event's organisation. Windows end at the event's
 * occurred_at and include the event itself.
 *
 * Fields mirror kratos_mind.schemas.ContextIn exactly (contract-tested).
 *
 * Scale note: the 7-day organisation aggregates scan the tenant's last week of
 * events via (organisation_id, …, occurred_at) indexes. Fine at the volumes
 * measured so far; at high volume these should come from hourly roll-ups
 * (Phase 6 benchmarking decides).
 */
class ContextBuilder
{
    private const BASELINE_HOURS = 168;

    /**
     * @return array<string, int|float>
     */
    public function for(SecurityEvent $event): array
    {
        $username = $event->metadata['username'] ?? null;

        $row = DB::selectOne(<<<'SQL'
            WITH p AS (
                SELECT ?::uuid AS org, ?::inet AS ip, ?::text AS username, ?::uuid AS asset,
                       ?::text AS type, ?::timestamptz AS t
            ),
            asset_hours AS (
                SELECT date_trunc('hour', e.occurred_at) AS h, count(*)::float AS c
                FROM security_events e, p
                WHERE p.asset IS NOT NULL AND e.organisation_id = p.org AND e.monitored_asset_id = p.asset
                  AND e.occurred_at >= p.t - interval '7 days 1 hour' AND e.occurred_at < p.t - interval '1 hour'
                GROUP BY 1
            ),
            baseline AS (
                SELECT coalesce(sum(c), 0) / 168.0 AS mean, count(*) AS buckets, coalesce(sum(c * c), 0) AS sum_sq
                FROM asset_hours
            ),
            week AS (
                SELECT count(*) AS total,
                       count(*) FILTER (WHERE e.event_type = p.type) AS of_type,
                       count(*) FILTER (WHERE e.event_type = p.type AND extract(hour FROM e.occurred_at AT TIME ZONE 'UTC') = extract(hour FROM p.t AT TIME ZONE 'UTC')) AS of_type_hour
                FROM security_events e, p
                WHERE e.organisation_id = p.org AND e.occurred_at > p.t - interval '7 days' AND e.occurred_at <= p.t
            )
            SELECT
                (SELECT count(*) FROM security_events e WHERE p.ip IS NOT NULL AND e.organisation_id = p.org AND e.source_ip = p.ip
                    AND e.occurred_at BETWEEN p.t - interval '5 minutes' AND p.t) AS src_ip_events_5m,
                (SELECT count(*) FROM security_events e WHERE p.ip IS NOT NULL AND e.organisation_id = p.org AND e.source_ip = p.ip
                    AND e.event_type = 'authentication_failure' AND e.occurred_at BETWEEN p.t - interval '15 minutes' AND p.t) AS src_ip_auth_failures_15m,
                (SELECT count(DISTINCT e.metadata->>'username') FROM security_events e WHERE p.ip IS NOT NULL AND e.organisation_id = p.org AND e.source_ip = p.ip
                    AND e.event_type = 'authentication_failure' AND e.occurred_at BETWEEN p.t - interval '1 hour' AND p.t) AS src_ip_distinct_users_1h,
                (SELECT count(*) FROM security_events e WHERE p.username IS NOT NULL AND e.organisation_id = p.org
                    AND e.event_type = 'authentication_failure' AND e.metadata @> jsonb_build_object('username', p.username)
                    AND e.occurred_at BETWEEN p.t - interval '15 minutes' AND p.t) AS user_auth_failures_15m,
                (SELECT count(*) FROM security_events e WHERE p.username IS NOT NULL AND e.organisation_id = p.org
                    AND e.event_type = 'permission_denied' AND e.metadata @> jsonb_build_object('username', p.username)
                    AND e.occurred_at BETWEEN p.t - interval '1 hour' AND p.t) AS user_permission_denied_1h,
                (SELECT count(*) FROM security_events e WHERE p.asset IS NOT NULL AND e.organisation_id = p.org AND e.monitored_asset_id = p.asset
                    AND e.occurred_at BETWEEN p.t - interval '1 hour' AND p.t) AS asset_events_1h,
                b.mean AS asset_hourly_mean_7d,
                -- population std over all 168 hourly buckets, empty hours counted as zero
                sqrt(greatest(b.sum_sq / 168.0 - b.mean * b.mean, 0)) AS asset_hourly_std_7d,
                w.total AS org_events_7d,
                CASE WHEN w.total > 0 THEN w.of_type::float / w.total ELSE 0 END AS type_share_7d,
                CASE WHEN w.of_type > 0 THEN w.of_type_hour::float / w.of_type ELSE 0 END AS type_hour_share_7d
            FROM p, baseline b, week w
            SQL, [
            $event->organisation_id,
            $event->source_ip,
            is_string($username) && $username !== '' ? $username : null,
            $event->monitored_asset_id,
            $event->event_type,
            $event->occurred_at,
        ]);

        return [
            'src_ip_events_5m' => (int) $row->src_ip_events_5m,
            'src_ip_auth_failures_15m' => (int) $row->src_ip_auth_failures_15m,
            'src_ip_distinct_users_1h' => (int) $row->src_ip_distinct_users_1h,
            'user_auth_failures_15m' => (int) $row->user_auth_failures_15m,
            'user_permission_denied_1h' => (int) $row->user_permission_denied_1h,
            'asset_events_1h' => (int) $row->asset_events_1h,
            'asset_hourly_mean_7d' => round((float) $row->asset_hourly_mean_7d, 4),
            'asset_hourly_std_7d' => round((float) $row->asset_hourly_std_7d, 4),
            'type_share_7d' => round(min(1.0, (float) $row->type_share_7d), 6),
            'type_hour_share_7d' => round(min(1.0, (float) $row->type_hour_share_7d), 6),
            'org_events_7d' => (int) $row->org_events_7d,
        ];
    }
}
