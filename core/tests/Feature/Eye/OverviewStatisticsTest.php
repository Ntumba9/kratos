<?php

namespace Tests\Feature\Eye;

use App\Domain\Eye\ProcessingStatus;
use App\Models\MonitoredAsset;
use App\Models\Role;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OverviewStatisticsTest extends TestCase
{
    public function test_overview_reports_real_counts_for_the_tenant_only(): void
    {
        $this->freezeTime();
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);
        MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'criticality' => 'critical', 'last_seen_at' => now()->subHour()]);
        MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'criticality' => 'low']);

        $event = fn (array $a) => SecurityEvent::factory()->create(array_merge(['organisation_id' => $org->id], $a));
        $event(['severity' => 'critical', 'received_at' => now()->subMinutes(2), 'processed_at' => now()->subMinutes(2)->addSeconds(1)]);
        $event(['severity' => 'high', 'received_at' => now()->subMinutes(2), 'processed_at' => now()->subMinutes(2)->addSeconds(3)]);
        $event(['severity' => 'low', 'received_at' => now()->subHours(5), 'event_type' => 'firewall_block']);
        $event(['severity' => 'medium', 'received_at' => now()->subDays(3)]); // outside 24h
        $event(['severity' => 'info', 'received_at' => now()->subMinutes(30), 'processing_status' => ProcessingStatus::DeadLettered]);
        SecurityEvent::factory()->count(5)->create(['received_at' => now()->subMinute()]); // other tenant

        $data = $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/stats/overview")->assertOk()->json('data');

        $this->assertSame(2, $data['assets']['total']);
        $this->assertSame(1, $data['assets']['seen_last_24h']);
        $this->assertSame(1, $data['assets']['by_criticality']['critical']);
        $this->assertSame(5, $data['events']['total']);
        $this->assertSame(4, $data['events']['last_24h']);
        $this->assertSame(2, $data['events']['high_or_critical_last_24h']);
        $this->assertSame(1, $data['events']['by_status']['dead_lettered']);
        $this->assertSame(['info' => 1, 'low' => 1, 'medium' => 0, 'high' => 1, 'critical' => 1], $data['events']['last_24h_by_severity']);

        $this->assertCount(61, $data['throughput']['per_minute']);
        $twoMinutesAgo = collect($data['throughput']['per_minute'])->firstWhere('minute', now()->subMinutes(2)->startOfMinute()->utc()->toIso8601String());
        $this->assertSame(2, $twoMinutesAgo['received']);
        $this->assertSame(2, $twoMinutesAgo['processed']);
        $this->assertEqualsWithDelta(2000.0, $data['throughput']['average_processing_ms'], 0.5);

        $this->assertCount(25, $data['timeline']);
        $this->assertSame(4, array_sum(array_map(fn ($h) => $h['info'] + $h['low'] + $h['medium'] + $h['high'] + $h['critical'], $data['timeline'])));
        $this->assertSame('authentication_failure', $data['top_event_types'][0]['event_type']);
        $this->assertNull($data['incidents']);
    }

    public function test_an_empty_organisation_reports_zeros_not_placeholders(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);

        $data = $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/stats/overview")->json('data');

        $this->assertSame(0, $data['events']['total']);
        $this->assertNull($data['throughput']['average_processing_ms']);
        $this->assertSame([], $data['top_event_types']);
    }

    public function test_retention_purges_only_events_past_each_organisations_period(): void
    {
        [, $short] = $this->orgAdmin();
        [, $long] = $this->orgAdmin();
        $short->update(['event_retention_days' => 7]);
        $long->update(['event_retention_days' => 90]);

        $old = SecurityEvent::factory()->create(['organisation_id' => $short->id, 'received_at' => now()->subDays(8)]);
        $recent = SecurityEvent::factory()->create(['organisation_id' => $short->id, 'received_at' => now()->subDays(6)]);
        $keptByLongerPolicy = SecurityEvent::factory()->create(['organisation_id' => $long->id, 'received_at' => now()->subDays(8)]);

        Artisan::call('kratos:events:prune', ['--chunk' => 1]);

        $this->assertNull(SecurityEvent::withoutTenancy()->find($old->id));
        $this->assertNotNull(SecurityEvent::withoutTenancy()->find($recent->id));
        $this->assertNotNull(SecurityEvent::withoutTenancy()->find($keptByLongerPolicy->id));
    }
}
