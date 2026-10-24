<?php

namespace App\Jobs;

use App\Domain\Eye\EventProcessor;
use App\Domain\Eye\ProcessOutcome;
use App\Domain\Mind\MindUnavailable;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organisation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one security event. Carries only identifiers: the event itself
 * is read from PostgreSQL, the system of record.
 *
 * Failure policy (ADR-0005, ADR-0007):
 *  - Errors: up to config('kratos.ingestion.tries') exceptions, with
 *    exponential backoff config('kratos.ingestion.backoff'); then failed()
 *    dead-letters the event (visible and replayable) — never silently dropped.
 *  - KRATOS MIND unavailable: the job releases itself with backoff capped at
 *    config('kratos.mind.unavailable_max_backoff_seconds'). Releases are not
 *    exceptions, so an outage delays analysis without dead-lettering healthy
 *    events. Bounded overall by retryUntil().
 */
class ProcessSecurityEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Failed attempts allowed (releases while MIND is down do not count). */
    public int $maxExceptions;

    public int $timeout = 60;

    public function __construct(
        public readonly string $eventId,
        public readonly string $organisationId,
    ) {
        $this->maxExceptions = (int) config('kratos.ingestion.tries');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('kratos.mind.unavailable_retry_window_minutes'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('kratos.ingestion.backoff');
    }

    public function handle(EventProcessor $processor, TenantContext $tenant): void
    {
        $organisation = Organisation::query()->find($this->organisationId);

        if ($organisation === null) {
            // Tenant deleted after ingestion; its events were cascade-deleted.
            Log::info('eye.process.organisation_missing', ['security_event_id' => $this->eventId]);

            return;
        }

        try {
            $outcome = $tenant->runAs($organisation, fn () => $processor->process($this->eventId));
        } catch (MindUnavailable $e) {
            $processor->markAwaitingAnalysis($this->eventId, $e);
            $delay = $this->unavailableBackoff();
            Log::warning('eye.process.analysis_unavailable', ['security_event_id' => $this->eventId, 'retry_in_seconds' => $delay]);
            $this->release($delay);

            return;
        } catch (Throwable $e) {
            $processor->markFailed($this->eventId, $e);

            throw $e; // let the queue apply backoff and retry
        }

        if ($outcome === ProcessOutcome::InProgressElsewhere) {
            // Another worker holds the claim. If it crashed, the claim goes
            // stale after EventProcessor::STALE_CLAIM_SECONDS; check back then.
            $this->release(EventProcessor::STALE_CLAIM_SECONDS);
        }

        Log::info('eye.process.'.match ($outcome) {
            ProcessOutcome::Processed => 'completed',
            ProcessOutcome::AlreadyProcessed => 'skipped_duplicate_delivery',
            ProcessOutcome::InProgressElsewhere => 'released_in_progress_elsewhere',
        }, ['security_event_id' => $this->eventId, 'attempt' => $this->attempts()]);
    }

    /**
     * 10 s, 20 s, 40 s … capped (default 300 s), by delivery attempt.
     */
    public function unavailableBackoff(): int
    {
        $attempt = max(1, $this->attempts());

        return (int) min(config('kratos.mind.unavailable_max_backoff_seconds'), 10 * 2 ** min($attempt - 1, 10));
    }

    public function failed(?Throwable $e): void
    {
        app(EventProcessor::class)->markDeadLettered($this->eventId, $e);

        Log::error('eye.process.dead_lettered', [
            'security_event_id' => $this->eventId,
            'exception' => $e ? $e::class : null,
        ]);
    }
}
