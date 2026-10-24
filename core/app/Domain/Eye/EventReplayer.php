<?php

namespace App\Domain\Eye;

use App\Domain\Audit\AuditLogger;
use App\Models\EventOutbox;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Re-queues a failed or dead-lettered event through the normal outbox path.
 */
final class EventReplayer
{
    public function __construct(
        private readonly OutboxPublisher $publisher,
        private readonly AuditLogger $audit,
    ) {}

    public function replay(SecurityEvent $event): SecurityEvent
    {
        if (! $event->processing_status->isReplayable()) {
            throw new ConflictHttpException("Only failed or dead-lettered events can be replayed (current status: {$event->processing_status->value}).");
        }

        $outbox = DB::transaction(function () use ($event): EventOutbox {
            $previous = $event->processing_status->value;

            $event->forceFill([
                'processing_status' => ProcessingStatus::Received,
                'processing_attempts' => 0,
                'last_error' => null,
            ])->save();

            $outbox = EventOutbox::query()->firstOrNew(['security_event_id' => $event->getKey()]);
            $outbox->forceFill([
                'organisation_id' => $event->organisation_id,
                'available_at' => now(),
                'published_at' => null,
                'publish_attempts' => 0,
                'last_error' => null,
                'created_at' => $outbox->created_at ?? now(),
            ])->save();

            $this->audit->record('security_event.replayed', $event, ['previous_status' => $previous]);

            return $outbox;
        });

        $this->publisher->publish($outbox);

        return $event->refresh();
    }
}
