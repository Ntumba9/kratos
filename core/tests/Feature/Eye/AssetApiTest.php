<?php

namespace Tests\Feature\Eye;

use App\Models\AuditLog;
use App\Models\MonitoredAsset;
use App\Models\Role;
use App\Models\SecurityEvent;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    private function base(string $orgId): string
    {
        return "/api/v1/organisations/{$orgId}/assets";
    }

    public function test_admins_register_update_and_retire_assets(): void
    {
        [$admin, $org] = $this->orgAdmin();

        $created = $this->actingAs($admin, 'web')->postJson($this->base($org->id), [
            'name' => 'billing-api',
            'asset_type' => 'application',
            'hostname' => 'billing.internal.example',
            'ip_address' => '192.0.2.20',
            'criticality' => 'high',
            'tags' => ['pci', 'tier:1'],
        ]);
        $created->assertCreated()->assertJsonPath('data.criticality', 'high')->assertJsonPath('data.environment', 'production');
        $id = $created->json('data.id');

        $this->actingAs($admin, 'web')->patchJson($this->base($org->id)."/{$id}", ['criticality' => 'critical'])
            ->assertOk()->assertJsonPath('data.criticality', 'critical');

        $this->actingAs($admin, 'web')->deleteJson($this->base($org->id)."/{$id}")->assertNoContent();

        $this->actingAs($admin, 'web')->getJson($this->base($org->id))->assertJsonCount(0, 'data');
        $this->actingAs($admin, 'web')->getJson($this->base($org->id).'?filter[include_retired]=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.retired_at', fn ($v) => $v !== null);

        $this->assertSame(
            ['asset.created', 'asset.updated', 'asset.retired'],
            AuditLog::withoutTenancy()->where('action', 'like', 'asset.%')->orderBy('created_at')->pluck('action')->all(),
        );
    }

    public function test_asset_names_are_unique_per_organisation_case_insensitively(): void
    {
        [$admin, $org] = $this->orgAdmin();
        MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'name' => 'web-01']);
        MonitoredAsset::factory()->create(['name' => 'web-02']); // other org: no clash

        $this->actingAs($admin, 'web')->postJson($this->base($org->id), ['name' => 'WEB-01', 'asset_type' => 'server'])
            ->assertStatus(422)->assertJsonValidationErrors(['name'], 'error.details');
        $this->actingAs($admin, 'web')->postJson($this->base($org->id), ['name' => 'web-02', 'asset_type' => 'server'])
            ->assertCreated();
    }

    public function test_asset_input_is_validated(): void
    {
        [$admin, $org] = $this->orgAdmin();

        $this->actingAs($admin, 'web')->postJson($this->base($org->id), [
            'name' => '<script>',
            'asset_type' => 'toaster',
            'hostname' => 'not a hostname',
            'ip_address' => '999.1.1.1',
            'criticality' => 'extreme',
            'tags' => ['Bad Tag'],
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'asset_type', 'hostname', 'ip_address', 'criticality', 'tags.0'], 'error.details');
    }

    public function test_viewers_can_list_but_not_change_assets(): void
    {
        [, $org] = $this->orgAdmin();
        [$viewer] = $this->memberWithRole(Role::VIEWER, $org);
        $asset = MonitoredAsset::factory()->create(['organisation_id' => $org->id]);

        $this->actingAs($viewer, 'web')->getJson($this->base($org->id))->assertOk()->assertJsonCount(1, 'data');
        $this->assertErrorEnvelope($this->actingAs($viewer, 'web')->postJson($this->base($org->id), ['name' => 'x1', 'asset_type' => 'server']), 403, 'forbidden');
        $this->assertErrorEnvelope($this->actingAs($viewer, 'web')->deleteJson($this->base($org->id)."/{$asset->id}"), 403, 'forbidden');
    }

    public function test_listing_filters_and_reports_recent_event_counts(): void
    {
        [$admin, $org] = $this->orgAdmin();
        $db = MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'name' => 'db-primary', 'asset_type' => 'database', 'criticality' => 'critical']);
        MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'name' => 'kiosk', 'asset_type' => 'endpoint', 'criticality' => 'low']);
        SecurityEvent::factory()->count(3)->create(['organisation_id' => $org->id, 'monitored_asset_id' => $db->id, 'received_at' => now()->subHour()]);
        SecurityEvent::factory()->create(['organisation_id' => $org->id, 'monitored_asset_id' => $db->id, 'received_at' => now()->subDays(2)]);

        $response = $this->actingAs($admin, 'web')->getJson($this->base($org->id).'?filter[criticality]=critical&filter[q]=PRIMARY');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'db-primary')
            ->assertJsonPath('data.0.events_last_24h', 3);
    }

    public function test_assets_of_other_organisations_are_not_found(): void
    {
        [$admin, $org] = $this->orgAdmin();
        $foreign = MonitoredAsset::factory()->create();

        foreach (['getJson', 'deleteJson'] as $method) {
            $this->assertErrorEnvelope($this->actingAs($admin, 'web')->{$method}($this->base($org->id)."/{$foreign->id}"), 404, 'not_found');
        }
        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->patchJson($this->base($org->id)."/{$foreign->id}", ['criticality' => 'low']),
            404,
            'not_found',
        );
        $this->assertNull($foreign->fresh()->deleted_at);
    }
}
