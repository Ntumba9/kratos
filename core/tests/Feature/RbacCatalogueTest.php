<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Tests\TestCase;

class RbacCatalogueTest extends TestCase
{
    public function test_seeder_matches_the_configured_catalogue(): void
    {
        foreach (config('kratos.roles') as $slug => $definition) {
            $role = Role::query()->with('permissions')->where('slug', $slug)->sole();

            $this->assertSame($definition['scope'], $role->scope);
            $this->assertEqualsCanonicalizing($definition['permissions'], $role->permissionSlugs());
        }

        $this->assertSame(count(config('kratos.permissions')), Permission::query()->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $this->assertSame(4, Role::query()->count());
        $this->assertSame(count(config('kratos.permissions')), Permission::query()->count());
    }

    public function test_roles_form_a_strict_privilege_hierarchy_within_an_organisation(): void
    {
        $permissions = fn (string $slug) => Role::query()->with('permissions')->where('slug', $slug)->sole()->permissionSlugs();

        $admin = $permissions(Role::ORG_ADMIN);
        $analyst = $permissions(Role::ANALYST);
        $viewer = $permissions(Role::VIEWER);

        $this->assertEmpty(array_diff($viewer, $analyst), 'Every viewer permission is also an analyst permission');
        $this->assertEmpty(array_diff($analyst, $admin), 'Every analyst permission is also an admin permission');
        $this->assertNotContains('incidents.approve_response', $analyst, 'Analysts cannot approve disruptive responses');
    }
}
