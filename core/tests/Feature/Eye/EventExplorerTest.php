<?php

namespace Tests\Feature\Eye;

use App\Domain\Eye\ProcessingStatus;
use App\Models\MonitoredAsset;
use App\Models\Role;
use App\Models\SecurityEvent;
use Tests\TestCase;

class EventExplorerTest extends TestCase
{
    public function test_events_can_be_filtered_by_severity_type_status_asset_ip_and_time(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);
        $asset = MonitoredAsset::factory()->create(['organisation_id' => $org->id]);
        $make = fn (array $a) => SecurityEvent::factory()->create(array_merge(['organisation_id' => $org->id], $a));

        $target = $make(['severity' => 'critical', 'event_type' => 'malware_detected', 'monitored_asset_id' => $asset->id,
            'source_ip' => '203.0.113.7', 'occurred_at' => now()->subHours(2)]);
        $make(['severity' => 'critical', 'event_type' => 'malware_detected', 'occurred_at' => now()->subDays(3)]);
        $make(['severity' => 'low', 'event_type' => 'malware_detected', 'monitored_asset_id' => $asset->id]);
        $make(['severity' => 'high', 'event_type' => 'firewall_block', 'processing_status' => ProcessingStatus::DeadLettered]);

        $base = "/api/v1/organisations/{$org->id}/events";
        $query = http_build_query(['filter' => [
            'severity' => 'high,critical',
            'event_type' => 'malware_detected',
            'asset_id' => $asset->id,
            'source_ip' => '203.0.113.7',
            'from' => now()->subDay()->toIso8601String(),
        ]]);

        $this->actingAs($viewer, 'web')->getJson("{$base}?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.asset.id', $asset->id);

        $this->actingAs($viewer, 'web')->getJson("{$base}?filter[status]=dead_lettered")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.event_type', 'firewall_block');

        $this->actingAs($viewer, 'web')->getJson("{$base}?filter[q]=FIREWALL")->assertJsonCount(1, 'data');
    }

    public function test_severity_sorts_by_rank_not_alphabetically(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);
        foreach (['low', 'critical', 'info', 'high', 'medium'] as $severity) {
            SecurityEvent::factory()->create(['organisation_id' => $org->id, 'severity' => $severity]);
        }

        $response = $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events?sort=-severity");

        $this->assertSame(['critical', 'high', 'medium', 'low', 'info'], array_column($response->json('data'), 'severity'));
    }

    public function test_event_detail_includes_pipeline_state_but_list_does_not(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);
        $event = SecurityEvent::factory()->create(['organisation_id' => $org->id, 'idempotency_key' => 'evt-77']);

        $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events")
            ->assertJsonMissingPath('data.0.processing')
            ->assertJsonMissingPath('data.0.fingerprint');

        $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.idempotency_key', 'evt-77')
            ->assertJsonStructure(['data' => ['processing' => ['attempts', 'last_error', 'processed_at']]]);
    }

    public function test_filters_are_validated(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);
        $base = "/api/v1/organisations/{$org->id}/events";

        $this->actingAs($viewer, 'web')->getJson("{$base}?filter[severity]=high;DROP")->assertStatus(422);
        $this->actingAs($viewer, 'web')->getJson("{$base}?filter[source_ip]=nope")->assertStatus(422);
        $this->actingAs($viewer, 'web')->getJson("{$base}?sort=metadata")->assertStatus(422);
    }

    public function test_events_of_other_organisations_are_invisible(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);
        SecurityEvent::factory()->count(2)->create(['organisation_id' => $org->id]);
        $foreign = SecurityEvent::factory()->create();

        $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events")
            ->assertJsonPath('meta.pagination.total', 2);
        $this->assertErrorEnvelope(
            $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events/{$foreign->id}"),
            404,
            'not_found',
        );
        $this->assertErrorEnvelope(
            $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$foreign->organisation_id}/events"),
            404,
            'not_found',
        );
    }
}
