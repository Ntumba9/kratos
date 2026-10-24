<?php

namespace App\Domain\Eye;

use App\Domain\Tenancy\TenantContext;
use App\Models\ApiCredential;
use App\Models\EventOutbox;
use App\Models\MonitoredAsset;
use App\Models\SecurityEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists validated events with deduplication and a transactional outbox.
 *
 * Guarantee: when ingest() returns, the event and its outbox record are
 * committed together. Publishing to Redis is attempted afterwards; if that
 * fails, the outbox relay publishes later (ADR-0005). Nothing that was
 * acknowledged can be lost by a Redis or worker failure.
 */
final class EventIngestor
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OutboxPublisher $publisher,
    ) {}

    /**
     * Must run inside a tenant context (set by the ingestion middleware).
     *
     * @throws IdempotencyConflict
     * @throws ValidationException when the referenced asset does not exist
     */
    public function ingest(EventData $data, ?ApiCredential $credential = null, string $errorPrefix = ''): IngestResult
    {
        $organisationId = $this->tenant->organisationId();
        $fingerprint = $data->fingerprint();

        if ($existing = $this->findDuplicate($data, $fingerprint)) {
            return $existing;
        }

        $assetId = $this->resolveAsset($data, $errorPrefix);

        try {
            [$event, $outbox] = DB::transaction(function () use ($data, $credential, $organisationId, $fingerprint, $assetId) {
                $event = SecurityEvent::create([
                    'organisation_id' => $organisationId,
                    'monitored_asset_id' => $assetId,
                    'api_credential_id' => $credential?->getKey(),
                    'event_type' => $data->eventType,
                    'source' => $data->source,
                    'severity' => $data->severity,
                    'occurred_at' => $data->occurredAt,
                    'received_at' => now(),
                    'source_ip' => $data->sourceIp(),
                    'metadata' => $data->metadata,
                    'idempotency_key' => $data->idempotencyKey,
                    'fingerprint' => $fingerprint,
                ]);

                $outbox = EventOutbox::create([
                    'security_event_id' => $event->getKey(),
                    'organisation_id' => $organisationId,
                    'available_at' => now(),
                ]);

                return [$event, $outbox];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request inserted the same event between our
            // duplicate check and insert; the unique indexes decided the race.
            return $this->findDuplicate($data, $fingerprint)
                ?? throw new \RuntimeException('Unique violation without a matching duplicate.');
        }

        // Outside the transaction: a Redis failure must not roll back the event.
        $this->publisher->publish($outbox);

        return new IngestResult($event->refresh(), duplicate: false);
    }

    private function findDuplicate(EventData $data, string $fingerprint): ?IngestResult
    {
        if ($data->idempotencyKey !== null) {
            $byKey = SecurityEvent::query()->where('idempotency_key', $data->idempotencyKey)->first();

            if ($byKey !== null) {
                if ($byKey->fingerprint !== $fingerprint) {
                    throw new IdempotencyConflict($byKey->getKey());
                }

                return new IngestResult($byKey, duplicate: true);
            }
        }

        $byFingerprint = SecurityEvent::query()->where('fingerprint', $fingerprint)->first();

        return $byFingerprint ? new IngestResult($byFingerprint, duplicate: true) : null;
    }

    private function resolveAsset(EventData $data, string $errorPrefix): ?string
    {
        if ($data->assetName === null) {
            return null;
        }

        $assetId = MonitoredAsset::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($data->assetName)])
            ->value('id');

        if ($assetId === null) {
            throw ValidationException::withMessages([
                $errorPrefix.'asset' => ['Unknown asset. Register it under Infrastructure first, or omit the field.'],
            ]);
        }

        return $assetId;
    }
}
