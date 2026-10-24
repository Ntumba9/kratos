<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\OrganisationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant isolation layer 3: even with a valid permission, a membership can
 * only be changed through the organisation it belongs to.
 */
class OrganisationMembershipPolicy
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function update(User $user, OrganisationMembership $membership): bool
    {
        return $this->inCurrentTenant($membership)
            && Gate::forUser($user)->allows('members.manage', $this->tenant->organisation());
    }

    public function delete(User $user, OrganisationMembership $membership): bool
    {
        return $this->update($user, $membership);
    }

    private function inCurrentTenant(OrganisationMembership $membership): bool
    {
        return $this->tenant->has() && $membership->organisation_id === $this->tenant->organisationId();
    }
}
