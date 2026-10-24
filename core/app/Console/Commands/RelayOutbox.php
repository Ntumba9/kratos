<?php

namespace App\Console\Commands;

use App\Domain\Eye\OutboxPublisher;
use App\Models\EventOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes outbox records that inline publishing could not deliver (Redis
 * down, process crash between commit and dispatch). Safe to run as several
 * replicas: rows are claimed with FOR UPDATE SKIP LOCKED.
 *
 * Handles SIGTERM/SIGINT gracefully: the current batch completes, then the
 * loop exits.
 */
class RelayOutbox extends Command
{
    protected $signature = 'kratos:outbox:relay
        {--once : Process a single batch and exit}
        {--batch=100 : Records per batch}
        {--sleep=2 : Seconds to wait when there is nothing to publish}
        {--grace=10 : Only relay records older than this many seconds (inline publishing usually handles newer ones)}';

    protected $description = 'Publish pending event outbox records to the processing queue';

    private bool $shouldStop = false;

    public function handle(OutboxPublisher $publisher): int
    {
        $this->trap([SIGTERM, SIGINT], function () {
            $this->shouldStop = true;
        });

        do {
            $published = 0;

            try {
                $published = $this->relayBatch($publisher, (int) $this->option('batch'), (int) $this->option('grace'));
            } catch (Throwable $e) {
                Log::error('eye.outbox.relay_error', ['exception' => $e::class, 'message' => $e->getMessage()]);

                if ($this->option('once')) {
                    $this->error($e->getMessage());

                    return self::FAILURE; // one-shot runs must surface failure
                }
                // Long-running mode: database briefly unavailable, etc. Keep going.
            }

            if ($published > 0) {
                Log::info('eye.outbox.relayed', ['count' => $published]);
            }

            if ($this->option('once')) {
                break;
            }

            if ($published === 0 && ! $this->shouldStop) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (! $this->shouldStop);

        return self::SUCCESS;
    }

    private function relayBatch(OutboxPublisher $publisher, int $batch, int $graceSeconds): int
    {
        return DB::transaction(function () use ($publisher, $batch, $graceSeconds): int {
            $pending = EventOutbox::query()
                ->whereNull('published_at')
                ->where('available_at', '<=', now())
                ->where('created_at', '<=', now()->subSeconds($graceSeconds))
                ->orderBy('id')
                ->limit($batch)
                // Concurrent relays each take a disjoint set of rows.
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            $published = 0;
            foreach ($pending as $outbox) {
                if ($this->shouldStop) {
                    break;
                }
                $published += $publisher->publish($outbox) ? 1 : 0;
            }

            return $published;
        });
    }
}
