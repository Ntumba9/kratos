<?php

namespace Tests\Feature\Mind;

use App\Models\AuditLog;
use App\Models\DetectionResult;
use App\Models\Role;
use App\Models\SecurityEvent;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

class IntelligenceApiTest extends TestCase
{
    use SendsSignedEvents;

    public function test_rules_are_listed_with_defaults_and_bounds(): void
    {
        [$viewer, $org] = $this->memberWithRole(Role::VIEWER);

        $rules = $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/detection-rules")->assertOk()->json('data');

        $this->assertCount(count(config('kratos.detection_rules')), $rules);
        $brute = collect($rules)->firstWhere('key', 'brute_force_source');
        $this->assertSame(['failures_15m' => 10], $brute['parameters']);
        $this->assertSame(['failures_15m' => ['min' => 3, 'max' => 1000]], $brute['bounds']);
        $this->assertTrue($brute['enabled']);
        $this->assertFalse($brute['customised']);
    }

    public function test_admins_tune_rules_within_bounds_and_changes_reach_mind_and_the_audit_log(): void
    {
        [$admin, $org] = $this->orgAdmin();
        $base = "/api/v1/organisations/{$org->id}/detection-rules";

        $this->actingAs($admin, 'web')->patchJson("{$base}/brute_force_source", ['parameters' => ['failures_15m' => 25]])
            ->assertOk()
            ->assertJsonPath('data.parameters.failures_15m', 25)
            ->assertJsonPath('data.customised', true);
        $this->actingAs($admin, 'web')->patchJson("{$base}/abnormal_timing", ['enabled' => false])->assertOk();

        $this->actingAs($admin, 'web')->patchJson("{$base}/brute_force_source", ['parameters' => ['failures_15m' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors(['parameters.failures_15m'], 'error.details');
        $this->actingAs($admin, 'web')->patchJson("{$base}/brute_force_source", ['parameters' => ['nonsense' => 3]])
            ->assertStatus(422)->assertJsonValidationErrors(['parameters.nonsense'], 'error.details');
        $this->actingAs($admin, 'web')->patchJson("{$base}/off_hours_privileged_change", ['parameters' => ['business_start_hour' => 20]])
            ->assertStatus(422);
        $this->actingAs($admin, 'web')->patchJson("{$base}/not_a_rule", ['enabled' => false])->assertStatus(404);

        ['credential' => $c, 'secret' => $s] = $this->ingestionClient($org);
        $this->sendSigned($c, $s, $this->eventPayload())->assertStatus(202);
        $rules = collect(end($this->mind->payloads)['rules'])->keyBy('key');
        $this->assertSame(25, $rules['brute_force_source']['parameters']['failures_15m']);
        $this->assertFalse($rules['abnormal_timing']['enabled']);

        $audit = AuditLog::withoutTenancy()->where('action', 'detection_rule.updated')->where('organisation_id', $org->id)->get();
        $this->assertCount(2, $audit);

        $this->actingAs($admin, 'web')->deleteJson("{$base}/brute_force_source")->assertNoContent();
        $reset = collect($this->actingAs($admin, 'web')->getJson($base)->json('data'))->firstWhere('key', 'brute_force_source');
        $this->assertSame(['failures_15m' => 10], $reset['parameters']);
        $this->assertFalse($reset['customised']);
    }

    public function test_overrides_are_per_organisation(): void
    {
        [$adminA, $orgA] = $this->orgAdmin();
        [$adminB, $orgB] = $this->orgAdmin();

        $this->actingAs($adminA, 'web')->patchJson("/api/v1/organisations/{$orgA->id}/detection-rules/brute_force_source", ['enabled' => false])->assertOk();

        $rules = $this->actingAs($adminB, 'web')->getJson("/api/v1/organisations/{$orgB->id}/detection-rules")->json('data');
        $this->assertTrue(collect($rules)->firstWhere('key', 'brute_force_source')['enabled']);
    }

    public function test_only_managers_can_tune_rules(): void
    {
        [, $org] = $this->orgAdmin();
        [$viewer] = $this->memberWithRole(Role::VIEWER, $org);
        [$analyst] = $this->memberWithRole(Role::ANALYST, $org);

        foreach ([$viewer, $analyst] as $user) {
            $this->assertErrorEnvelope(
                $this->actingAs($user, 'web')->patchJson("/api/v1/organisations/{$org->id}/detection-rules/brute_force_source", ['enabled' => false]),
                403,
                'forbidden',
            );
        }
    }

    public function test_detections_and_statistics_are_tenant_isolated(): void
    {
        ['credential' => $c, 'secret' => $s, 'organisation' => $org] = $this->ingestionClient();
        foreach (range(1, 11) as $i) {
            $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['source_ip' => '203.0.113.66', 'username' => 'u', 'failed_attempts' => $i]]));
        }
        ['credential' => $c2, 'secret' => $s2] = $this->ingestionClient();
        foreach (range(1, 11) as $i) {
            $this->sendSigned($c2, $s2, $this->eventPayload(['metadata' => ['source_ip' => '203.0.113.66', 'username' => 'u', 'failed_attempts' => $i]]));
        }
        [$viewer] = $this->memberWithRole(Role::VIEWER, $org);

        $detections = $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/detections?filter[detector]=rule")->assertOk();
        $this->assertSame(2, $detections->json('meta.pagination.total'), 'events 10 and 11 hit the brute-force rule');
        $this->assertSame(4, DetectionResult::withoutTenancy()->where('detector', 'rule')->count(), 'both tenants');

        $stats = $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/stats/intelligence")->assertOk()->json('data');
        $this->assertSame(11, $stats['analysed_last_24h']);
        $this->assertSame(2, $stats['verdicts']['malicious']);
        $this->assertSame(9, $stats['verdicts']['benign']);
        $this->assertEquals([['rule_key' => 'brute_force_source', 'count' => 2, 'max_score' => 80]], $stats['rule_hits']);
        $this->assertSame(11, array_sum(array_column($stats['risk_histogram'], 'count')));
        $this->assertSame(2, $stats['risk_histogram'][8]['count'], 'risk 86 falls in 80–90');
    }

    public function test_event_detail_includes_analysis_and_evidence(): void
    {
        ['credential' => $c, 'secret' => $s, 'organisation' => $org] = $this->ingestionClient();
        foreach (range(1, 10) as $i) {
            $id = $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['source_ip' => '203.0.113.66', 'username' => 'u', 'failed_attempts' => $i]]))->json('data.id');
        }
        [$viewer] = $this->memberWithRole(Role::VIEWER, $org);

        $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events/{$id}")
            ->assertOk()
            ->assertJsonPath('data.verdict', 'malicious')
            ->assertJsonPath('data.analysis.model_version', 'fs1-test')
            ->assertJsonPath('data.analysis.detections.0.detector', 'rule')
            ->assertJsonPath('data.analysis.detections.0.rule_key', 'brute_force_source');

        $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$org->id}/events?sort=-risk_score&filter[verdict]=malicious")
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_model_card_is_served_and_falls_back_when_mind_is_down(): void
    {
        [$viewer] = $this->memberWithRole(Role::VIEWER);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/intelligence/model')
            ->assertOk()
            ->assertJsonPath('data.version', 'fs1-test')
            ->assertJsonPath('data.synthetic_evaluation', true);

        $this->mind->unavailable = true;
        $this->actingAs($viewer, 'web')->getJson('/api/v1/intelligence/model')
            ->assertOk()
            ->assertJsonPath('data.version', 'fs1-test');
    }

    public function test_model_card_without_any_known_model_is_503(): void
    {
        [$viewer] = $this->memberWithRole(Role::VIEWER);
        $this->mind->unavailable = true;

        $this->assertErrorEnvelope($this->actingAs($viewer, 'web')->getJson('/api/v1/intelligence/model'), 503, 'model_unavailable');
        $this->assertSame(0, SecurityEvent::withoutTenancy()->count());
    }
}
