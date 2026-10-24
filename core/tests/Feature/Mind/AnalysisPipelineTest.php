<?php

namespace Tests\Feature\Mind;

use App\Domain\Eye\ProcessingStatus;
use App\Domain\Mind\MindRejected;
use App\Jobs\ProcessSecurityEvent;
use App\Models\DetectionResult;
use App\Models\ModelVersion;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

class AnalysisPipelineTest extends TestCase
{
    use SendsSignedEvents;

    private function bruteForce(int $count, string $ip = '203.0.113.66'): array
    {
        ['credential' => $c, 'secret' => $s, 'organisation' => $org] = $this->ingestionClient();
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->sendSigned($c, $s, $this->eventPayload([
                'timestamp' => now()->subSeconds(($count - $i) * 20)->utc()->format('Y-m-d\TH:i:s\Z'),
                'metadata' => ['source_ip' => $ip, 'username' => 'finance.lead', 'failed_attempts' => $i],
            ]))->assertStatus(202)->json('data.id');
        }

        return [$org, $ids];
    }

    public function test_processed_events_carry_risk_verdict_category_and_evidence(): void
    {
        [, $ids] = $this->bruteForce(12);

        $last = SecurityEvent::withoutTenancy()->findOrFail(end($ids));
        $this->assertSame(ProcessingStatus::Processed, $last->processing_status);
        $this->assertEquals(86.0, (float) $last->risk_score);
        $this->assertSame('malicious', $last->verdict);
        $this->assertSame('credential_attack', $last->threat_category);
        $this->assertNotNull($last->analysed_at);
        $this->assertNotNull($last->model_version_id);

        $evidence = DetectionResult::withoutTenancy()->where('security_event_id', $last->id)->get();
        $this->assertEqualsCanonicalizing(['rule', 'anomaly_model'], $evidence->pluck('detector')->all());
        $this->assertStringContainsString('12 failed logins from 203.0.113.66', $evidence->firstWhere('detector', 'rule')->explanation);

        $first = SecurityEvent::withoutTenancy()->findOrFail($ids[0]);
        $this->assertSame('benign', $first->verdict, 'the first failure alone is not an attack');
    }

    public function test_context_sent_to_mind_reflects_tenant_history(): void
    {
        [, $ids] = $this->bruteForce(12);
        // Another tenant's events from the same IP must not leak into the context.
        $this->bruteForce(5);

        $payload = collect($this->mind->payloads)->firstWhere('event.id', end($ids));
        $this->assertSame(12, $payload['context']['src_ip_auth_failures_15m']);
        $this->assertSame(1, $payload['context']['src_ip_distinct_users_1h']);
        $this->assertSame(12, $payload['context']['user_auth_failures_15m']);
        $this->assertSame(['failures_15m' => 10], collect($payload['rules'])->firstWhere('key', 'brute_force_source')['parameters']);
        $this->assertCount(count(config('kratos.detection_rules')), $payload['rules']);
    }

    public function test_reanalysis_replaces_evidence_instead_of_duplicating_it(): void
    {
        [$org, $ids] = $this->bruteForce(12);
        $event = SecurityEvent::withoutTenancy()->findOrFail(end($ids));
        $before = DetectionResult::withoutTenancy()->where('security_event_id', $event->id)->count();

        $event->forceFill(['processing_status' => ProcessingStatus::DeadLettered])->save();
        [$analyst] = $this->memberWithRole('analyst', $org);
        $this->actingAs($analyst, 'web')->postJson("/api/v1/organisations/{$org->id}/events/{$event->id}/replay")->assertStatus(202);

        $this->assertSame($before, DetectionResult::withoutTenancy()->where('security_event_id', $event->id)->count());
        $this->assertSame(1, ModelVersion::query()->count());
    }

    public function test_mind_outage_delays_analysis_without_failing_the_job(): void
    {
        Queue::fake();
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $id = $this->sendSigned($c, $s, $this->eventPayload())->json('data.id');
        $event = SecurityEvent::withoutTenancy()->findOrFail($id);

        $this->mind->unavailable = true;
        $job = (new ProcessSecurityEvent($event->id, $event->organisation_id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertReleased(10);
        $job->assertNotFailed();
        $event->refresh();
        $this->assertSame(ProcessingStatus::Failed, $event->processing_status);
        $this->assertStringContainsString('Analysis service unavailable', $event->last_error);
        $this->assertNull($event->analysed_at);

        // MIND recovers: the next delivery completes the analysis.
        $this->mind->unavailable = false;
        $retry = (new ProcessSecurityEvent($event->id, $event->organisation_id))->withFakeQueueInteractions();
        $this->app->call([$retry, 'handle']);

        $retry->assertNotReleased();
        $this->assertSame(ProcessingStatus::Processed, $event->fresh()->processing_status);
        $this->assertNotNull($event->fresh()->risk_score);
    }

    public function test_outage_backoff_grows_and_is_capped(): void
    {
        $job = new class('e', 'o') extends ProcessSecurityEvent
        {
            public int $fakeAttempts = 1;

            public function attempts(): int
            {
                return $this->fakeAttempts;
            }
        };

        $delays = [];
        foreach ([1, 2, 3, 6, 20] as $attempt) {
            $job->fakeAttempts = $attempt;
            $delays[] = $job->unavailableBackoff();
        }

        $this->assertSame([10, 20, 40, 300, 300], $delays);
        $this->assertTrue($job->retryUntil()->greaterThan(now()->addHours(5)));
    }

    public function test_contract_violations_count_as_failures(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        Queue::fake();
        $id = $this->sendSigned($c, $s, $this->eventPayload())->json('data.id');
        $event = SecurityEvent::withoutTenancy()->findOrFail($id);
        $this->mind->responder = fn () => throw new MindRejected('HTTP 422');

        $job = (new ProcessSecurityEvent($event->id, $event->organisation_id))->withFakeQueueInteractions();
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('A rejected request must throw so it counts toward maxExceptions.');
        } catch (MindRejected) {
        }

        $this->assertSame(ProcessingStatus::Failed, $event->fresh()->processing_status);
    }
}
