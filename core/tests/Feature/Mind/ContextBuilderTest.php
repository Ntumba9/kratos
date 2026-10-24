<?php

namespace Tests\Feature\Mind;

use App\Domain\Mind\ContextBuilder;
use App\Models\MonitoredAsset;
use App\Models\Organisation;
use App\Models\SecurityEvent;
use Tests\TestCase;

/**
 * The SQL that computes MIND's context, checked against hand-counted fixtures.
 */
class ContextBuilderTest extends TestCase
{
    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->org = Organisation::factory()->create();
    }

    private function event(array $attributes): SecurityEvent
    {
        return SecurityEvent::factory()->create(array_merge([
            'organisation_id' => $this->org->id,
            'event_type' => 'authentication_failure',
            'occurred_at' => now(),
            'source_ip' => '203.0.113.9',
            'metadata' => ['username' => 'alice'],
        ], $attributes));
    }

    public function test_source_ip_and_username_windows(): void
    {
        foreach ([1, 4, 9, 14] as $minutesAgo) {
            $this->event(['occurred_at' => now()->subMinutes($minutesAgo)]);
        }
        $this->event(['occurred_at' => now()->subMinutes(20)]);                                  // outside 15 min
        $this->event(['occurred_at' => now()->subMinutes(2), 'metadata' => ['username' => 'bob']]);
        $this->event(['occurred_at' => now()->subMinutes(30), 'metadata' => ['username' => 'carol']]);
        $this->event(['occurred_at' => now()->subMinutes(3), 'event_type' => 'authentication_success']);
        $this->event(['occurred_at' => now()->subMinutes(1), 'source_ip' => '198.51.100.1']);    // other IP
        $this->event(['occurred_at' => now()->subMinutes(10), 'event_type' => 'permission_denied', 'source_ip' => null]);
        SecurityEvent::factory()->create(['occurred_at' => now(), 'source_ip' => '203.0.113.9', 'event_type' => 'authentication_failure']); // other tenant
        $subject = $this->event([]);

        $ctx = app(ContextBuilder::class)->for($subject);

        $this->assertSame(5, $ctx['src_ip_events_5m'], 'subject, 1 and 4 min, bob@2, success@3');
        $this->assertSame(6, $ctx['src_ip_auth_failures_15m'], 'subject, 1, 4, 9, 14, bob');
        $this->assertSame(3, $ctx['src_ip_distinct_users_1h'], 'alice, bob, carol');
        $this->assertSame(6, $ctx['user_auth_failures_15m'], 'alice from both IPs within 15 min');
        $this->assertSame(1, $ctx['user_permission_denied_1h']);
    }

    public function test_asset_baseline_counts_empty_hours_as_zero(): void
    {
        $asset = MonitoredAsset::factory()->create(['organisation_id' => $this->org->id]);
        // 168 events spread one per hour over the prior week (excluding the last hour) => mean 1, std 0.
        for ($h = 2; $h <= 169; $h++) {
            $this->event(['occurred_at' => now()->subHours($h), 'monitored_asset_id' => $asset->id, 'event_type' => 'firewall_block']);
        }
        foreach (range(1, 9) as $m) {
            $this->event(['occurred_at' => now()->subMinutes($m * 5), 'monitored_asset_id' => $asset->id]);
        }
        $subject = $this->event(['monitored_asset_id' => $asset->id]);

        $ctx = app(ContextBuilder::class)->for($subject);

        $this->assertSame(10, $ctx['asset_events_1h']);
        $this->assertEqualsWithDelta(1.0, $ctx['asset_hourly_mean_7d'], 0.01);
        $this->assertEqualsWithDelta(0.0, $ctx['asset_hourly_std_7d'], 0.01);
    }

    public function test_type_and_hour_shares(): void
    {
        $this->travelTo(now()->setTime(14, 30));
        foreach (range(1, 6) as $d) {
            $this->event(['occurred_at' => now()->subDays($d)]);                           // 14:30 on prior days
            $this->event(['occurred_at' => now()->subDays($d)->setTime(3, 0)]);            // 03:00
            $this->event(['occurred_at' => now()->subDays($d), 'event_type' => 'firewall_block']);
        }
        $subject = $this->event([]);

        $ctx = app(ContextBuilder::class)->for($subject);

        $this->assertSame(19, $ctx['org_events_7d']);
        $this->assertEqualsWithDelta(13 / 19, $ctx['type_share_7d'], 1e-4);
        $this->assertEqualsWithDelta(7 / 13, $ctx['type_hour_share_7d'], 1e-4);
    }

    public function test_events_without_ip_user_or_asset_get_zeros(): void
    {
        $subject = $this->event(['source_ip' => null, 'metadata' => []]);

        $ctx = app(ContextBuilder::class)->for($subject);

        $this->assertSame(0, $ctx['src_ip_events_5m']);
        $this->assertSame(0, $ctx['user_auth_failures_15m']);
        $this->assertSame(0, $ctx['asset_events_1h']);
    }
}
