<?php

namespace App\Domain\Tenancy;

use App\Models\Organisation;
use App\Models\OrganisationMembership;

/**
 * The organisation the current unit of work (HTTP request or queued job)
 * operates on. Bound as a scoped singleton so it is reset between requests
 * and between jobs on long-running workers.
 */
final class TenantContext
{
    private ?Organisation $organisation = null;

    private ?OrganisationMembership $membership = null;

    public function set(Organisation $organisation, ?OrganisationMembership $membership = null): void
    {
        if ($membership !== null && $membership->organisation_id !== $organisation->getKey()) {
            throw new \InvalidArgumentException('Membership does not belong to the given organisation.');
        }

        $this->organisation = $organisation;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organisation = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->organisation !== null;
    }

    public function organisation(): Organisation
    {
        return $this->organisation ?? throw new MissingTenantContext;
    }

    public function organisationId(): string
    {
        return $this->organisation()->getKey();
    }

    public function membership(): ?OrganisationMembership
    {
        return $this->membership;
    }

    /**
     * Run a callback inside a specific tenant context and restore the
     * previous context afterwards (used by jobs and console commands).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Organisation $organisation, callable $callback): mixed
    {
        $previous = [$this->organisation, $this->membership];
        $this->set($organisation);

        try {
            return $callback();
        } finally {
            [$this->organisation, $this->membership] = $previous;
        }
    }
}
