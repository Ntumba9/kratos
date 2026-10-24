<?php

namespace Tests\Feature\Ingest;

use App\Jobs\ProcessSecurityEvent;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

class IngestionDeduplicationTest extends TestCase
{
    use SendsSignedEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_retrying_with_the_same_idempotency_key_returns_the_original_event(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $payload = $this->eventPayload();

        $first = $this->sendSigned($c, $s, $payload, headers: ['Idempotency-Key' => 'evt-0001']);
        $retry = $this->sendSigned($c, $s, $payload, headers: ['Idempotency-Key' => 'evt-0001']);

        $first->assertStatus(202);
        $retry->assertStatus(200)
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, SecurityEvent::withoutTenancy()->count());
        Queue::assertPushed(ProcessSecurityEvent::class, 1);
    }

    public function test_reusing_an_idempotency_key_for_a_different_payload_is_a_conflict(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $first = $this->sendSigned($c, $s, $this->eventPayload(['idempotency_key' => 'evt-0002']));
        $conflict = $this->sendSigned($c, $s, $this->eventPayload(['idempotency_key' => 'evt-0002', 'severity' => 'critical']));

        $this->assertErrorEnvelope($conflict, 409, 'idempotency_conflict');
        $conflict->assertJsonPath('error.details.existing_event_id', $first->json('data.id'));
        $this->assertSame(1, SecurityEvent::withoutTenancy()->count());
    }

    public function test_identical_payloads_without_a_key_are_deduplicated_by_fingerprint(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $payload = $this->eventPayload(['metadata' => ['source_ip' => '192.0.2.10', 'failed_attempts' => 12]]);
        $reordered = $this->eventPayload(['metadata' => ['failed_attempts' => 12, 'source_ip' => '192.0.2.10']]);

        $first = $this->sendSigned($c, $s, $payload);
        $second = $this->sendSigned($c, $s, $reordered); // same content, different key order

        $second->assertStatus(200)->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, SecurityEvent::withoutTenancy()->count());
    }

    public function test_equivalent_timestamps_in_different_offsets_are_the_same_event(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $instant = now()->subMinutes(5)->startOfSecond();

        $utc = $this->sendSigned($c, $s, $this->eventPayload(['timestamp' => $instant->utc()->format('Y-m-d\TH:i:s\Z')]));
        $offset = $this->sendSigned($c, $s, $this->eventPayload(['timestamp' => $instant->setTimezone('-05:00')->format('Y-m-d\TH:i:sP')]));

        $offset->assertStatus(200)->assertJsonPath('data.id', $utc->json('data.id'));
    }

    public function test_different_events_are_not_deduplicated(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['failed_attempts' => 1]]))->assertStatus(202);
        $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['failed_attempts' => 2]]))->assertStatus(202);

        $this->assertSame(2, SecurityEvent::withoutTenancy()->count());
    }

    public function test_deduplication_is_scoped_per_organisation(): void
    {
        ['credential' => $a, 'secret' => $as] = $this->ingestionClient();
        ['credential' => $b, 'secret' => $bs] = $this->ingestionClient();
        $payload = $this->eventPayload(['idempotency_key' => 'shared-key']);

        $first = $this->sendSigned($a, $as, $payload)->assertStatus(202);
        $second = $this->sendSigned($b, $bs, $payload)->assertStatus(202);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
    }
}
