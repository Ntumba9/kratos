<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires the RBAC catalogue to be seeded (RbacSeeder).
 *
 * @extends Factory<OrganisationMembership>
 */
class OrganisationMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'user_id' => User::factory(),
            'role_id' => fn () => Role::findBySlug(Role::VIEWER)->getKey(),
        ];
    }

    public function role(string $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::findBySlug($slug)->getKey()]);
    }
}
