<?php

namespace Tests\Feature\Ingest;

use App\Models\MonitoredAsset;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

class IngestionValidationTest extends TestCase
{
    use SendsSignedEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * Each case transforms a valid payload into an invalid one.
     *
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, list<string>}>
     */
    public static function invalidPayloads(): iterable
    {
        $set = fn (array $changes) => fn (array $p) => array_merge($p, $changes);

        yield 'missing required fields' => [fn () => ['metadata' => []], ['event_type', 'source', 'severity', 'timestamp']];
        yield 'unknown top-level field' => [$set(['extra' => 1]), ['extra']];
        yield 'invalid severity' => [$set(['severity' => 'apocalyptic']), ['severity']];
        yield 'unknown event type' => [$set(['event_type' => 'totally_made_up']), ['event_type']];
        yield 'event type injection' => [$set(['event_type' => "x'; DROP TABLE users;--"]), ['event_type']];
        yield 'timestamp without timezone' => [$set(['timestamp' => '2026-10-23 12:00:00']), ['timestamp']];
        yield 'timestamp in the future' => [$set(['timestamp' => '2099-01-01T00:00:00Z']), ['timestamp']];
        yield 'metadata as list' => [$set(['metadata' => [1, 2, 3]]), ['metadata']];
        yield 'typed fields with wrong types' => [$set(['metadata' => ['source_ip' => 'not-an-ip', 'failed_attempts' => 'twelve']]), ['metadata.source_ip', 'metadata.failed_attempts']];
        yield 'metadata nested too deep' => [$set(['metadata' => ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]]), ['metadata']];
        yield 'invalid metadata key' => [$set(['metadata' => ['bad key!' => 1]]), ['metadata']];
        yield 'source with spaces' => [$set(['source' => 'not allowed here']), ['source']];
        yield 'severity of wrong type' => [$set(['severity' => 3]), ['severity']];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidPayloads')]
    public function test_malformed_events_are_rejected_with_field_errors(callable $mutate, array $fields): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $response = $this->sendSigned($c, $s, $mutate($this->eventPayload()));

        $this->assertErrorEnvelope($response, 422, 'validation_failed');
        $response->assertJsonValidationErrors($fields, 'error.details');
        $this->assertSame(0, SecurityEvent::withoutTenancy()->count());
    }

    public function test_the_example_event_from_the_specification_is_accepted_and_normalised(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $response = $this->sendSigned($c, $s, [
            'event_type' => 'authentication_failure',
            'source' => 'application_server',
            'severity' => 'medium',
            'timestamp' => now()->subHour()->setTimezone('+02:00')->format('Y-m-d\TH:i:sP'),
            'metadata' => ['failed_attempts' => 12, 'source_ip' => '192.0.2.10'],
        ]);

        $response->assertStatus(202);
        $event = SecurityEvent::withoutTenancy()->findOrFail($response->json('data.id'));
        $this->assertSame('192.0.2.10', $event->source_ip);
        $this->assertSame('UTC', $event->occurred_at->utc()->timezoneName);
        $this->assertTrue($event->occurred_at->equalTo(now()->subHour()->startOfSecond()) || abs($event->occurred_at->diffInSeconds(now()->subHour())) < 5);
    }

    public function test_custom_event_types_are_allowed_with_the_custom_prefix(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $this->sendSigned($c, $s, $this->eventPayload(['event_type' => 'custom.vpn_geo_mismatch', 'metadata' => ['country' => 'XX']]))
            ->assertStatus(202);
    }

    public function test_events_can_reference_registered_assets_only(): void
    {
        ['credential' => $c, 'secret' => $s, 'organisation' => $org] = $this->ingestionClient();
        $asset = MonitoredAsset::factory()->create(['organisation_id' => $org->id, 'name' => 'billing-api']);

        $ok = $this->sendSigned($c, $s, $this->eventPayload(['asset' => 'Billing-API']));
        $ok->assertStatus(202);
        $this->assertSame($asset->id, SecurityEvent::withoutTenancy()->find($ok->json('data.id'))->monitored_asset_id);

        $this->sendSigned($c, $s, $this->eventPayload(['asset' => 'does-not-exist', 'metadata' => ['failed_attempts' => 99]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset'], 'error.details');
    }

    public function test_an_asset_of_another_organisation_cannot_be_referenced(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        MonitoredAsset::factory()->create(['name' => 'foreign-asset']); // different organisation

        $this->sendSigned($c, $s, $this->eventPayload(['asset' => 'foreign-asset']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset'], 'error.details');
    }

    public function test_oversized_bodies_are_rejected_before_processing(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $body = json_encode($this->eventPayload(['metadata' => ['blob' => str_repeat('x', 300_000)]]));

        $this->assertErrorEnvelope($this->sendSigned($c, $s, $body), 413, 'payload_too_large');
    }

    public function test_metadata_size_limit_is_enforced(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $metadata = [];
        foreach (range(1, 10) as $i) {
            $metadata["field{$i}"] = str_repeat('y', 2_000);
        }

        $this->sendSigned($c, $s, $this->eventPayload(['metadata' => $metadata]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata'], 'error.details');
    }

    public function test_invalid_json_and_wrong_content_type_are_rejected(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $this->assertErrorEnvelope($this->sendSigned($c, $s, '{"event_type": '), 400, 'invalid_json');
        $this->assertErrorEnvelope(
            $this->sendSigned($c, $s, json_encode($this->eventPayload()), headers: ['Content-Type' => 'text/plain']),
            415,
            'unsupported_media_type',
        );
    }

    public function test_batches_report_per_event_results(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $response = $this->sendSigned($c, $s, ['events' => [
            $this->eventPayload(['metadata' => ['failed_attempts' => 1]]),
            $this->eventPayload(['severity' => 'nope']),
            $this->eventPayload(['metadata' => ['failed_attempts' => 1]]), // duplicate of #0
        ]], '/api/v1/ingest/events/batch');

        $response->assertStatus(202)
            ->assertJsonPath('meta.summary', ['accepted' => 1, 'duplicate' => 1, 'rejected' => 1])
            ->assertJsonPath('data.0.status', 'accepted')
            ->assertJsonPath('data.1.status', 'rejected')
            ->assertJsonPath('data.2.status', 'duplicate')
            ->assertJsonPath('data.2.id', $response->json('data.0.id'));
        $this->assertArrayHasKey('events.1.severity', $response->json('data.1.errors'));
    }

    public function test_batch_limits_are_enforced(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $tooMany = array_fill(0, 101, $this->eventPayload());

        $this->sendSigned($c, $s, ['events' => $tooMany], '/api/v1/ingest/events/batch')
            ->assertStatus(422)->assertJsonValidationErrors(['events'], 'error.details');

        $this->sendSigned($c, $s, ['events' => []], '/api/v1/ingest/events/batch')->assertStatus(422);

        $allBad = $this->sendSigned($c, $s, ['events' => [['severity' => 'x']]], '/api/v1/ingest/events/batch');
        $allBad->assertStatus(422)->assertJsonPath('meta.summary.rejected', 1);
    }
}
