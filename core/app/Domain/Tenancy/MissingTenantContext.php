<?php

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is queried without a tenant context.
 * Failing closed here is deliberate: the alternative is an unscoped query
 * that returns every organisation's rows.
 */
final class MissingTenantContext extends RuntimeException
{
    public function __construct(string $message = 'A tenant context is required to query tenant-owned data.')
    {
        parent::__construct($message);
    }
}
