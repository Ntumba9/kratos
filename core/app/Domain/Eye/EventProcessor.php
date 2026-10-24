<?php

namespace App\Domain\Eye;

use App\Domain\Mind\EventAnalyser;
use App\Domain\Mind\MindUnavailable;
use App\Models\MonitoredAsset;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes one event. Idempotent: a conditional UPDATE "claims" the event,
 * so concurrent or repeated deliveries of the same job cannot process it
 * twice. A claim older than STALE_CLAIM_SECONDS is considered abandoned (the
 * worker died mid-job) and may be re-claimed.
 *
 * Steps: (1) enrichment — link the event to an asset by hostname when the
 * sender did not name one, record asset activity; (2) detection — rules,
 * anomaly scoring and risk assessment by KRATOS MIND (ADR-0007).
 */
class EventProcessor
{
    public const STALE_CLAIM_SECONDS = 300;

    public function __construct(private readonly EventAnalyser $analyser) {}

    public function process(string $eventId): ProcessOutcome
    {
        if (! $this->claim($eventId)) {
            // toBase(): read the raw column; Eloquent's value() would apply the enum cast.
            $status = SecurityEvent::query()->whereKey($eventId)->toBase()->value('processing_status');

            return $status === ProcessingStatus::Processing->value
                ? ProcessOutcome::InProgressElsewhere
                : ProcessOutcome::AlreadyProcessed;
        }

        $event = SecurityEvent::query()->findOrFail($eventId);

        DB::transaction(function () use ($event): void {
            $this->linkAssetByHostname($event);

            if ($event->monitored_asset_id !== null) {
                MonitoredAsset::query()
                    ->whereKey($event->monitored_asset_id)
                    ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $event->occurred_at))
                    ->update(['last_seen_at' => $event->occurred_at]);
            }

            $event->save();
        });

        // Outside the enrichment transaction: a slow or failed network call
        // must not hold row locks. Throws MindUnavailable / MindRejected.
        $this->analyser->analyse($event);

        $event->forceFill([
            'processing_status' => ProcessingStatus::Processed,
            'processed_at' => now(),
            'last_error' => null,
        ])->save();

        return ProcessOutcome::Processed;
    }

    /**
     * Record a failed attempt; the queue will retry with backoff.
     */
    public function markFailed(string $eventId, Throwable $e): void
    {
        SecurityEvent::withoutTenancy()->whereKey($eventId)->update([
            'processing_status' => ProcessingStatus::Failed->value,
            'last_error' => Str::limit($e::class.': '.$e->getMessage(), 1000),
            'updated_at' => now(),
        ]);
    }

    /**
     * MIND is unavailable: the event waits (not a failed attempt; ADR-0007).
     */
    public function markAwaitingAnalysis(string $eventId, MindUnavailable $e): void
    {
        SecurityEvent::withoutTenancy()->whereKey($eventId)->update([
            'processing_status' => ProcessingStatus::Failed->value,
            'last_error' => Str::limit('Analysis service unavailable; waiting to retry. '.$e->getMessage(), 1000),
            'updated_at' => now(),
        ]);
    }

    /**
     * Retries exhausted: park the event where operators can see and replay it.
     */
    public function markDeadLettered(string $eventId, ?Throwable $e): void
    {
        SecurityEvent::withoutTenancy()->whereKey($eventId)->update([
            'processing_status' => ProcessingStatus::DeadLettered->value,
            'last_error' => $e ? Str::limit($e::class.': '.$e->getMessage(), 1000) : 'Retries exhausted.',
            'updated_at' => now(),
        ]);
    }

    private function claim(string $eventId): bool
    {
        $claimable = [ProcessingStatus::Received->value, ProcessingStatus::Queued->value, ProcessingStatus::Failed->value];

        return SecurityEvent::query()
            ->whereKey($eventId)
            ->where(function ($q) use ($claimable) {
                $q->whereIn('processing_status', $claimable)
                    ->orWhere(fn ($stale) => $stale
                        ->where('processing_status', ProcessingStatus::Processing->value)
                        ->where('processing_started_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS)));
            })
            ->update([
                'processing_status' => ProcessingStatus::Processing->value,
                'processing_started_at' => now(),
                'processing_attempts' => DB::raw('processing_attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    private function linkAssetByHostname(SecurityEvent $event): void
    {
        $hostname = $event->metadata['hostname'] ?? null;

        if ($event->monitored_asset_id !== null || ! is_string($hostname) || $hostname === '') {
            return;
        }

        $assetId = MonitoredAsset::query()->whereRaw('lower(hostname) = ?', [mb_strtolower($hostname)])->value('id');

        if ($assetId !== null) {
            $event->monitored_asset_id = $assetId;
        }
    }
}
