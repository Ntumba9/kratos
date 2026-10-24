<?php

namespace App\Domain\Eye;

/**
 * Lifecycle of a security event through the processing pipeline:
 *
 *   received ─(outbox published)─► queued ─(worker claims)─► processing ─► processed
 *                                     ▲                          │
 *                                     └──── failed (will retry) ◄┘
 *                                                │ retries exhausted
 *                                                ▼
 *                                          dead_lettered ─(replay)─► queued
 */
enum ProcessingStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isReplayable(): bool
    {
        return in_array($this, [self::Failed, self::DeadLettered], true);
    }
}
