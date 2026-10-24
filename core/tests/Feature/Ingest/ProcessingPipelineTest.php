<?php

namespace Tests\Feature\Ingest;

use App\Domain\Eye\EventProcessor;
use App\Domain\Eye\OutboxPublisher;
use App\Domain\Eye\ProcessingStatus;
use App\Domain\Eye\ProcessOutcome;
use App\Domain\Mind\EventAnalyser;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessSecurityEvent;
use App\Models\EventOutbox;
use App\Models\MonitoredAsset;
use App\Models\Organisation;
use App\Models\SecurityEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

/**
 * Delivery guarantees of the event pipeline (ADR-0005): at-least-once
 * delivery, idempotent processing, retries, dead-lettering, replay.
 */
class ProcessingPipelineTest extends TestCase
{
    use SendsSignedEvents;

    private function failingDispatcher(): void
    {
        // Simulates Redis being unreachable at dispatch time.
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));
        });
        $this->app->forgetInstance(OutboxPublisher::class);
    }

    public function test_an_accepted_event_is_committed_with_its_outbox_record_and_queued(): void
    {
        Queue::fake();
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $id = $this->sendSigned($c, $s, $this->eventPayload())->assertStatus(202)->json('data.id');

        $outbox = EventOutbox::query()->where('security_event_id', $id)->sole();
        $this->assertNotNull($outbox->published_at);
        $this->assertSame(ProcessingStatus::Queued, SecurityEvent::withoutTenancy()->find($id)->processing_status);
        Queue::assertPushedOn('events', ProcessSecurityEvent::class, fn (ProcessSecurityEvent $job) => $job->eventId === $id);
    }

    public function test_events_are_still_accepted_when_redis_is_down_and_are_relayed_later(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $this->failingDispatcher();

        $response = $this->sendSigned($c, $s, $this->eventPayload());

        // Accepted and durable despite the queue outage.
        $response->assertStatus(202);
        $id = $response->json('data.id');
        $outbox = EventOutbox::query()->where('security_event_id', $id)->sole();
        $this->assertNull($outbox->published_at);
        $this->assertSame(1, $outbox->publish_attempts);
        $this->assertStringContainsString('Connection refused', $outbox->last_error);
        $this->assertSame(ProcessingStatus::Received, SecurityEvent::withoutTenancy()->find($id)->processing_status);

        // Redis recovers; the relay publishes the backlog.
        // mock() replaced the contract alias with an instance; restore the real binding.
        $this->app->forgetInstance(Dispatcher::class);
        $this->app->alias(\Illuminate\Bus\Dispatcher::class, Dispatcher::class);
        $this->app->forgetInstance(OutboxPublisher::class);
        Queue::fake();
        $outbox->forceFill(['available_at' => now()->subSecond(), 'created_at' => now()->subMinute()])->save();

        $this->assertSame(0, Artisan::call('kratos:outbox:relay', ['--once' => true]));

        $this->assertNotNull($outbox->fresh()->published_at);
        Queue::assertPushed(ProcessSecurityEvent::class, fn ($job) => $job->eventId === $id);
    }

    public function test_the_relay_backs_off_while_publishing_keeps_failing(): void
    {
        $this->failingDispatcher();
        $event = SecurityEvent::factory()->status(ProcessingStatus::Received)->create();
        $outbox = EventOutbox::forceCreate([
            'security_event_id' => $event->id,
            'organisation_id' => $event->organisation_id,
            'available_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
        ]);

        Artisan::call('kratos:outbox:relay', ['--once' => true]);
        $afterFirst = $outbox->fresh();
        Artisan::call('kratos:outbox:relay', ['--once' => true]); // not yet available again

        $this->assertSame(1, $outbox->fresh()->publish_attempts);
        $this->assertTrue($afterFirst->available_at->isFuture());
        $this->assertNull($outbox->fresh()->published_at);
    }

    public function test_processing_marks_the_event_processed_and_updates_the_asset(): void
    {
        ['credential' => $c, 'secret' => $s, 'organisation' => $org] = $this->ingestionClient();
        $asset = MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'name' => 'web-01', 'hostname' => 'web-01.corp.example']);

        // QUEUE_CONNECTION=sync in tests: the job runs as soon as it is published.
        $id = $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['hostname' => 'WEB-01.corp.example']]))->json('data.id');

        $event = SecurityEvent::withoutTenancy()->findOrFail($id);
        $this->assertSame(ProcessingStatus::Processed, $event->processing_status);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, $event->processing_attempts);
        $this->assertSame($asset->id, $event->monitored_asset_id, 'linked by hostname');
        $this->assertTrue($asset->fresh()->last_seen_at->equalTo($event->occurred_at));
    }

    public function test_redelivery_of_a_processed_event_is_a_no_op(): void
    {
        $event = SecurityEvent::factory()->status(ProcessingStatus::Queued)->create();
        $processor = app(EventProcessor::class);
        $tenant = app(TenantContext::class);
        $org = Organisation::find($event->organisation_id);

        $first = $tenant->runAs($org, fn () => $processor->process($event->id));
        $processedAt = $event->fresh()->processed_at;
        $this->travel(5)->seconds();
        $second = $tenant->runAs($org, fn () => $processor->process($event->id));

        $this->assertSame(ProcessOutcome::Processed, $first);
        $this->assertSame(ProcessOutcome::AlreadyProcessed, $second);
        $this->assertTrue($processedAt->equalTo($event->fresh()->processed_at));
        $this->assertSame(1, $event->fresh()->processing_attempts);
    }

    public function test_a_fresh_claim_by_another_worker_is_not_stolen_but_a_stale_one_is(): void
    {
        $event = SecurityEvent::factory()->status(ProcessingStatus::Processing)->create(['processing_started_at' => now()]);
        $org = Organisation::find($event->organisation_id);
        $run = fn () => app(TenantContext::class)->runAs($org, fn () => app(EventProcessor::class)->process($event->id));

        $this->assertSame(ProcessOutcome::InProgressElsewhere, $run());

        // The other worker crashed; after the stale threshold the claim is recoverable.
        $this->travel(EventProcessor::STALE_CLAIM_SECONDS + 1)->seconds();
        $this->assertSame(ProcessOutcome::Processed, $run());
    }

    public function test_job_failures_are_recorded_and_exhaustion_dead_letters_the_event(): void
    {
        $event = SecurityEvent::factory()->status(ProcessingStatus::Queued)->create();
        $this->app->instance(EventProcessor::class, new class(app(EventAnalyser::class)) extends EventProcessor
        {
            public function process(string $eventId): ProcessOutcome
            {
                throw new RuntimeException('enrichment backend unavailable');
            }
        });

        $job = new ProcessSecurityEvent($event->id, $event->organisation_id);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The job should rethrow so the queue retries it.');
        } catch (RuntimeException) {
        }

        $this->assertSame(ProcessingStatus::Failed, $event->fresh()->processing_status);
        $this->assertStringContainsString('enrichment backend unavailable', $event->fresh()->last_error);

        // Queue gives up after the final attempt.
        $job->failed(new RuntimeException('enrichment backend unavailable'));
        $this->assertSame(ProcessingStatus::DeadLettered, $event->fresh()->processing_status);
    }

    public function test_retry_policy_is_exponential_backoff(): void
    {
        $job = new ProcessSecurityEvent('id', 'org');

        $this->assertSame(5, $job->maxExceptions);
        $this->assertSame([10, 30, 90, 270], $job->backoff());
        $backoff = $job->backoff();
        for ($i = 1; $i < count($backoff); $i++) {
            $this->assertSame(3 * $backoff[$i - 1], $backoff[$i]);
        }
    }

    public function test_dead_lettered_events_can_be_replayed_by_analysts(): void
    {
        Queue::fake();
        [$analyst, $org] = $this->memberWithRole('analyst');
        $event = SecurityEvent::factory()->status(ProcessingStatus::DeadLettered)
            ->create(['organisation_id' => $org->id, 'last_error' => 'boom', 'processing_attempts' => 5]);

        $response = $this->actingAs($analyst, 'web')->postJson("/api/v1/organisations/{$org->id}/events/{$event->id}/replay");

        $response->assertStatus(202)->assertJsonPath('data.processing_status', 'queued');
        $this->assertSame(0, $event->fresh()->processing_attempts);
        $this->assertNull($event->fresh()->last_error);
        Queue::assertPushed(ProcessSecurityEvent::class, fn ($job) => $job->eventId === $event->id);
    }

    public function test_processed_events_cannot_be_replayed_and_viewers_cannot_replay(): void
    {
        [$admin, $org] = $this->orgAdmin();
        [$viewer] = $this->memberWithRole('viewer', $org);
        $processed = SecurityEvent::factory()->create(['organisation_id' => $org->id]);
        $dead = SecurityEvent::factory()->status(ProcessingStatus::DeadLettered)->create(['organisation_id' => $org->id]);

        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->postJson("/api/v1/organisations/{$org->id}/events/{$processed->id}/replay"),
            409,
            'conflict',
        );
        $this->assertErrorEnvelope(
            $this->actingAs($viewer, 'web')->postJson("/api/v1/organisations/{$org->id}/events/{$dead->id}/replay"),
            403,
            'forbidden',
        );
    }

    public function test_jobs_for_deleted_organisations_are_discarded_safely(): void
    {
        $job = new ProcessSecurityEvent('00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000000');

        $this->expectNotToPerformAssertions();

        $this->app->call([$job, 'handle']);
    }
}
