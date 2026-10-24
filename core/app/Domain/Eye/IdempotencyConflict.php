<?php

namespace App\Domain\Eye;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The idempotency key was already used for a different payload.
 */
final class IdempotencyConflict extends ConflictHttpException
{
    public function __construct(public readonly string $existingEventId)
    {
        parent::__construct('This idempotency key was already used for a different event payload.');
    }
}
