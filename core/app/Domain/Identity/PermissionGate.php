<?php

namespace App\Domain\Identity;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Registers one Gate ability per permission in config('kratos.permissions').
 *
 *   Gate::allows('members.manage', $organisation)
 *   $this->authorize('audit_logs.view', $organisation)
 *
 * Organisation permissions are evaluated against the caller's membership in
 * the given organisation (taken from TenantContext when it matches, so the
 * common path costs no query). Platform permissions come from the user's
 * platform role. There is no implicit super-user bypass.
 */
final class PermissionGate
{
    public static function register(): void
    {
        foreach (array_keys(config('kratos.permissions')) as $permission) {
            Gate::define($permission, function (User $user, ?Organisation $organisation = null) use ($permission): bool {
                if (Str::startsWith($permission, 'platform.')) {
                    return (bool) $user->platformRole?->grants($permission);
                }

                if ($organisation === null) {
                    return false;
                }

                return (bool) self::membershipFor($user, $organisation)?->grants($permission);
            });
        }
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(User $user, Organisation $organisation): array
    {
        return self::membershipFor($user, $organisation)?->role->permissionSlugs() ?? [];
    }

    private static function membershipFor(User $user, Organisation $organisation): ?OrganisationMembership
    {
        $tenant = app(TenantContext::class);
        $membership = $tenant->has() ? $tenant->membership() : null;

        if ($membership !== null
            && $membership->organisation_id === $organisation->getKey()
            && $membership->user_id === $user->getKey()) {
            return $membership;
        }

        return $user->membershipIn($organisation);
    }
}
