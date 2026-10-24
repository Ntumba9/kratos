<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\AuditLogger;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Organisation and membership lifecycle, including the invariant that every
 * organisation always keeps at least one Organisation Administrator.
 */
final class OrganisationManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $owner, string $name): Organisation
    {
        return DB::transaction(function () use ($owner, $name): Organisation {
            $organisation = Organisation::create([
                'name' => $name,
                'slug' => Organisation::uniqueSlugFor($name),
            ]);

            $membership = $organisation->memberships()->create([
                'user_id' => $owner->getKey(),
                'role_id' => Role::findBySlug(Role::ORG_ADMIN)->getKey(),
            ]);

            $this->audit->record('organisation.created', $organisation, ['name' => $name], $organisation, $owner);
            $this->audit->record('membership.created', $membership, ['user_id' => $owner->getKey(), 'role' => Role::ORG_ADMIN], $organisation, $owner);

            return $organisation;
        });
    }

    /**
     * @param  array{name?: string, event_retention_days?: int}  $attributes
     */
    public function update(Organisation $organisation, array $attributes): Organisation
    {
        $organisation->fill($attributes);
        $changes = $organisation->getDirty();
        $original = array_intersect_key($organisation->getOriginal(), $changes);
        $organisation->save();

        if ($changes !== []) {
            $this->audit->record('organisation.updated', $organisation, ['before' => $original, 'after' => $changes], $organisation);
        }

        return $organisation;
    }

    public function addMember(Organisation $organisation, User $user, Role $role, User $actor): OrganisationMembership
    {
        $this->assertOrganisationRole($role);

        if ($organisation->memberships()->where('user_id', $user->getKey())->exists()) {
            throw new MembershipConflict('This user is already a member of the organisation.');
        }

        try {
            $membership = $organisation->memberships()->create([
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'invited_by' => $actor->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) { // concurrent duplicate add
            throw new MembershipConflict('This user is already a member of the organisation.');
        }

        $this->audit->record('membership.created', $membership, ['user_id' => $user->getKey(), 'role' => $role->slug], $organisation);

        return $membership->load(['user', 'role']);
    }

    public function changeRole(OrganisationMembership $membership, Role $role): OrganisationMembership
    {
        $this->assertOrganisationRole($role);

        return DB::transaction(function () use ($membership, $role): OrganisationMembership {
            $this->lockAdmins($membership->organisation_id);
            $previous = $membership->role;

            if ($previous->slug === Role::ORG_ADMIN && $role->slug !== Role::ORG_ADMIN) {
                $this->assertNotLastAdmin($membership);
            }

            $membership->role()->associate($role)->save();

            $this->audit->record('membership.role_changed', $membership, [
                'user_id' => $membership->user_id,
                'from' => $previous->slug,
                'to' => $role->slug,
            ], $membership->organisation);

            return $membership->load(['user', 'role']);
        });
    }

    public function removeMember(OrganisationMembership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            $this->lockAdmins($membership->organisation_id);

            if ($membership->role->slug === Role::ORG_ADMIN) {
                $this->assertNotLastAdmin($membership);
            }

            $membership->delete();

            $this->audit->record('membership.removed', $membership, [
                'user_id' => $membership->user_id,
                'role' => $membership->role->slug,
            ], $membership->organisation);
        });
    }

    /**
     * Serialise concurrent demotions/removals of administrators so two
     * simultaneous requests cannot both pass the "not the last admin" check.
     */
    private function lockAdmins(string $organisationId): void
    {
        OrganisationMembership::query()
            ->where('organisation_id', $organisationId)
            ->where('role_id', Role::findBySlug(Role::ORG_ADMIN)->getKey())
            ->lockForUpdate()
            ->get(['id']);
    }

    private function assertNotLastAdmin(OrganisationMembership $membership): void
    {
        $otherAdmins = OrganisationMembership::query()
            ->where('organisation_id', $membership->organisation_id)
            ->where('role_id', Role::findBySlug(Role::ORG_ADMIN)->getKey())
            ->whereKeyNot($membership->getKey())
            ->count();

        if ($otherAdmins === 0) {
            throw new MembershipConflict('An organisation must keep at least one Organisation Administrator.');
        }
    }

    private function assertOrganisationRole(Role $role): void
    {
        if ($role->scope !== Role::SCOPE_ORGANISATION) {
            throw new \InvalidArgumentException('Only organisation-scoped roles can be assigned to members.');
        }
    }
}
