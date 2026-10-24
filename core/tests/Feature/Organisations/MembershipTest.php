<?php

namespace Tests\Feature\Organisations;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    public function test_admins_can_add_verified_users(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        $newcomer = User::factory()->create(['email' => 'newcomer@example.test']);

        $response = $this->actingAs($admin, 'web')->postJson("/api/v1/organisations/{$organisation->id}/members", [
            'email' => 'NEWCOMER@example.test',
            'role' => Role::ANALYST,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.id', $newcomer->id)
            ->assertJsonPath('data.role.slug', Role::ANALYST);

        $this->assertTrue(AuditLog::withoutTenancy()
            ->where('organisation_id', $organisation->id)
            ->where('action', 'membership.created')
            ->where('actor_user_id', $admin->id)
            ->exists());
    }

    public function test_unknown_or_unverified_users_cannot_be_added(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        User::factory()->unverified()->create(['email' => 'pending@example.test']);

        foreach (['nobody@example.test', 'pending@example.test'] as $email) {
            $this->actingAs($admin, 'web')
                ->postJson("/api/v1/organisations/{$organisation->id}/members", ['email' => $email, 'role' => Role::VIEWER])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['email'], 'error.details');
        }
    }

    public function test_duplicate_membership_is_a_conflict(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        [$existing] = $this->memberWithRole(Role::VIEWER, $organisation);

        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->postJson("/api/v1/organisations/{$organisation->id}/members", [
                'email' => $existing->email,
                'role' => Role::ANALYST,
            ]),
            409,
            'conflict',
        );
    }

    public function test_platform_roles_cannot_be_assigned_inside_an_organisation(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin, 'web')
            ->postJson("/api/v1/organisations/{$organisation->id}/members", ['email' => $user->email, 'role' => Role::PLATFORM_ADMIN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role'], 'error.details');
    }

    public function test_role_changes_are_applied_and_audited(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        [, , $membership] = $this->memberWithRole(Role::VIEWER, $organisation);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/organisations/{$organisation->id}/members/{$membership->id}", ['role' => Role::ANALYST])
            ->assertOk()
            ->assertJsonPath('data.role.slug', Role::ANALYST);

        $audit = AuditLog::withoutTenancy()->where('action', 'membership.role_changed')->sole();
        $this->assertSame('viewer', $audit->metadata['from']);
        $this->assertSame('analyst', $audit->metadata['to']);
    }

    public function test_the_last_administrator_cannot_be_demoted_or_removed(): void
    {
        [$admin, $organisation, $adminMembership] = $this->orgAdmin();

        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->patchJson(
                "/api/v1/organisations/{$organisation->id}/members/{$adminMembership->id}",
                ['role' => Role::VIEWER],
            ),
            409,
            'conflict',
        );

        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->deleteJson("/api/v1/organisations/{$organisation->id}/members/{$adminMembership->id}"),
            409,
            'conflict',
        );

        $this->assertSame(Role::ORG_ADMIN, $adminMembership->fresh()->role->slug);
    }

    public function test_an_administrator_can_step_down_when_another_remains(): void
    {
        [$admin, $organisation, $adminMembership] = $this->orgAdmin();
        $this->memberWithRole(Role::ORG_ADMIN, $organisation);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/organisations/{$organisation->id}/members/{$adminMembership->id}", ['role' => Role::VIEWER])
            ->assertOk();
    }

    public function test_members_can_be_removed(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        [, , $membership] = $this->memberWithRole(Role::ANALYST, $organisation);

        $this->actingAs($admin, 'web')
            ->deleteJson("/api/v1/organisations/{$organisation->id}/members/{$membership->id}")
            ->assertNoContent();

        $this->assertModelMissing($membership);
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function roleMatrix(): iterable
    {
        // role, can view members, can manage members
        yield 'organisation administrator' => [Role::ORG_ADMIN, true, true];
        yield 'security analyst' => [Role::ANALYST, true, false];
        yield 'read-only viewer' => [Role::VIEWER, true, false];
    }

    #[DataProvider('roleMatrix')]
    public function test_member_management_permissions_follow_the_role(string $role, bool $canView, bool $canManage): void
    {
        [$actor, $organisation] = $this->memberWithRole($role);
        $this->memberWithRole(Role::ORG_ADMIN, $organisation); // keep an admin around
        [, , $target] = $this->memberWithRole(Role::VIEWER, $organisation);

        $this->actingAs($actor, 'web')
            ->getJson("/api/v1/organisations/{$organisation->id}/members")
            ->assertStatus($canView ? 200 : 403);

        $this->actingAs($actor, 'web')
            ->patchJson("/api/v1/organisations/{$organisation->id}/members/{$target->id}", ['role' => Role::ANALYST])
            ->assertStatus($canManage ? 200 : 403);
    }

    public function test_member_listing_is_paginated(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        foreach (range(1, 3) as $_) {
            $this->memberWithRole(Role::VIEWER, $organisation);
        }

        $this->actingAs($admin, 'web')
            ->getJson("/api/v1/organisations/{$organisation->id}/members?per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 4)
            ->assertJsonPath('meta.pagination.page', 2)
            ->assertJsonPath('meta.pagination.last_page', 2);
    }
}
