<?php

namespace Tests\Feature\Platform;

use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    public function test_platform_administrators_can_list_all_organisations(): void
    {
        $admin = User::factory()->create(['platform_role_id' => Role::findBySlug(Role::PLATFORM_ADMIN)->id]);
        Organisation::factory()->count(3)->create();

        $this->actingAs($admin, 'web')->getJson('/api/v1/platform/organisations')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'member_count']]]);
    }

    public function test_organisation_administrators_are_not_platform_administrators(): void
    {
        [$orgAdmin] = $this->orgAdmin();

        $this->assertErrorEnvelope(
            $this->actingAs($orgAdmin, 'web')->getJson('/api/v1/platform/organisations'),
            403,
            'forbidden',
        );
    }

    public function test_role_catalogue_lists_all_four_roles_with_permissions(): void
    {
        [$user] = $this->memberWithRole(Role::VIEWER);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/roles')->assertOk();

        $this->assertSame(
            [Role::PLATFORM_ADMIN, Role::ORG_ADMIN, Role::ANALYST, Role::VIEWER],
            array_column($response->json('data'), 'slug'),
        );

        $viewer = collect($response->json('data'))->firstWhere('slug', Role::VIEWER);
        $this->assertNotContains('members.manage', $viewer['permissions']);
        $this->assertNotContains('incidents.manage', $viewer['permissions']);
        $this->assertContains('events.view', $viewer['permissions']);
    }
}
