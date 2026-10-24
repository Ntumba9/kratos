<?php

namespace App\Http\Resources;

use App\Models\OrganisationMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated principal as seen by KRATOS COMMAND: profile, platform
 * role, and every organisation membership with its effective permissions.
 * The SPA uses permissions only to hide controls; the API enforces them.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    public static function for(User $user): self
    {
        $user->load([
            'platformRole.permissions',
            'memberships' => fn ($query) => $query->whereHas('organisation')->with(['organisation', 'role.permissions']),
        ]);

        return new self($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => (new UserResource($this->resource))->resolve($request),
            'platform_role' => $this->platformRole ? [
                'slug' => $this->platformRole->slug,
                'name' => $this->platformRole->name,
                'permissions' => $this->platformRole->permissionSlugs(),
            ] : null,
            'memberships' => $this->memberships
                ->sortBy(fn (OrganisationMembership $m) => mb_strtolower($m->organisation->name))
                ->values()
                ->map(fn (OrganisationMembership $m) => [
                    'id' => $m->id,
                    'organisation' => [
                        'id' => $m->organisation->id,
                        'name' => $m->organisation->name,
                        'slug' => $m->organisation->slug,
                        // Demonstration tenants are labelled in the UI.
                        'is_demo' => (bool) ($m->organisation->settings['demo'] ?? false),
                    ],
                    'role' => ['slug' => $m->role->slug, 'name' => $m->role->name],
                    'permissions' => $m->role->permissionSlugs(),
                ])
                ->all(),
        ];
    }
}
