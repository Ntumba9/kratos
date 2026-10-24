<?php

namespace App\Domain\Eye;

use App\Jobs\ProcessSecurityEvent;
use App\Models\EventOutbox;
use App\Models\SecurityEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves outbox records onto the Redis queue. Used both inline (right after
 * ingestion, for low latency) and by the relay (for anything that failed).
 *
 * Publishing can occasionally happen twice (e.g. the process dies after
 * dispatching but before marking the row published). That is acceptable:
 * the job is idempotent, so delivery is at-least-once with no duplicate effect.
 */
final class OutboxPublisher
{
    /** Maximum delay between relay retries of a failing publish. */
    private const MAX_BACKOFF_SECONDS = 300;

    public function __construct(private readonly Dispatcher $bus) {}

    public function publish(EventOutbox $outbox): bool
    {
        try {
            $this->bus->dispatch(
                (new ProcessSecurityEvent($outbox->security_event_id, $outbox->organisation_id))
                    ->onQueue(config('kratos.ingestion.queue')),
            );
        } catch (Throwable $e) {
            $attempts = $outbox->publish_attempts + 1;
            $outbox->forceFill([
                'publish_attempts' => $attempts,
                'available_at' => now()->addSeconds(min(2 ** $attempts, self::MAX_BACKOFF_SECONDS)),
                'last_error' => Str::limit($e::class.': '.$e->getMessage(), 1000),
            ])->save();

            Log::warning('eye.outbox.publish_failed', [
                'security_event_id' => $outbox->security_event_id,
                'attempts' => $attempts,
                'exception' => $e::class,
            ]);

            return false;
        }

        DB::transaction(function () use ($outbox): void {
            $outbox->forceFill([
                'published_at' => now(),
                'publish_attempts' => $outbox->publish_attempts + 1,
                'last_error' => null,
            ])->save();

            // Only advance the status if the worker has not already picked it up.
            SecurityEvent::withoutTenancy()
                ->whereKey($outbox->security_event_id)
                ->where('processing_status', ProcessingStatus::Received->value)
                ->update(['processing_status' => ProcessingStatus::Queued->value, 'updated_at' => now()]);
        });

        return true;
    }
}
