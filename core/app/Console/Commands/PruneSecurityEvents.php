<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enforces each organisation's event retention period
 * (organisations.event_retention_days). Deletes in small chunks so the job
 * never holds long locks on a busy table.
 */
class PruneSecurityEvents extends Command
{
    protected $signature = 'kratos:events:prune {--chunk=1000 : Rows deleted per statement}';

    protected $description = 'Delete security events older than each organisation\'s retention period';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $total = 0;

        Organisation::query()->select(['id', 'event_retention_days'])->chunkById(100, function ($organisations) use ($chunk, &$total) {
            foreach ($organisations as $organisation) {
                $cutoff = now()->subDays($organisation->event_retention_days);

                do {
                    // Cross-tenant maintenance by design: scoped explicitly by
                    // organisation_id, not through the request tenant context.
                    $deleted = DB::delete(
                        'DELETE FROM security_events WHERE id IN (
                            SELECT id FROM security_events
                            WHERE organisation_id = ? AND received_at < ?
                            LIMIT ?
                        )',
                        [$organisation->id, $cutoff, $chunk],
                    );
                    $total += $deleted;
                } while ($deleted === $chunk);
            }
        });

        Log::info('eye.retention.pruned', ['deleted' => $total]);
        $this->info("Deleted {$total} security events past retention.");

        return self::SUCCESS;
    }
}
