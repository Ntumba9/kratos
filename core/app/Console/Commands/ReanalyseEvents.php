<?php

namespace App\Console\Commands;

use App\Domain\Eye\OutboxPublisher;
use App\Domain\Eye\ProcessingStatus;
use App\Models\EventOutbox;
use App\Models\SecurityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-queues processed events that have no analysis (e.g. received before
 * KRATOS MIND was deployed) through the normal outbox path. Idempotent:
 * analysis replaces evidence, and already-analysed events are skipped unless
 * --all is given.
 */
class ReanalyseEvents extends Command
{
    protected $signature = 'kratos:events:reanalyse
        {--hours=24 : Only events received within this many hours}
        {--all : Also re-analyse events that already have an analysis}
        {--limit=10000 : Maximum events to queue}';

    protected $description = 'Queue processed events for (re-)analysis by KRATOS MIND';

    public function handle(OutboxPublisher $publisher): int
    {
        $query = SecurityEvent::withoutTenancy()
            ->where('processing_status', ProcessingStatus::Processed->value)
            ->where('received_at', '>=', now()->subHours((int) $this->option('hours')))
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('analysed_at'))
            ->orderBy('received_at')
            ->limit((int) $this->option('limit'));

        $queued = 0;
        foreach ($query->cursor() as $event) {
            $outbox = DB::transaction(function () use ($event): EventOutbox {
                SecurityEvent::withoutTenancy()->whereKey($event->id)->update([
                    'processing_status' => ProcessingStatus::Received->value,
                    'updated_at' => now(),
                ]);

                $outbox = EventOutbox::query()->firstOrNew(['security_event_id' => $event->id]);
                $outbox->forceFill([
                    'organisation_id' => $event->organisation_id,
                    'available_at' => now(),
                    'published_at' => null,
                    'publish_attempts' => 0,
                    'last_error' => null,
                    'created_at' => $outbox->created_at ?? now(),
                ])->save();

                return $outbox;
            });

            $publisher->publish($outbox);
            $queued++;
        }

        $this->info("Queued {$queued} events for analysis.");

        return self::SUCCESS;
    }
}
