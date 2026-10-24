<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Models\Role;
use Tests\TestCase;

/**
 * Proves that a member of organisation A cannot read or change organisation
 * B's resources through any route, ID substitution, or query path.
 */
class TenantIsolationTest extends TestCase
{
    public function test_non_members_get_404_for_every_tenant_route(): void
    {
        [$attacker] = $this->orgAdmin();
        [, $victimOrg, $victimMembership] = $this->orgAdmin();

        $id = $victimOrg->id;
        $requests = [
            ['GET', "/api/v1/organisations/{$id}"],
            ['PATCH', "/api/v1/organisations/{$id}", ['name' => 'Pwned']],
            ['GET', "/api/v1/organisations/{$id}/members"],
            ['POST', "/api/v1/organisations/{$id}/members", ['email' => $attacker->email, 'role' => Role::ORG_ADMIN]],
            ['PATCH', "/api/v1/organisations/{$id}/members/{$victimMembership->id}", ['role' => Role::VIEWER]],
            ['DELETE', "/api/v1/organisations/{$id}/members/{$victimMembership->id}"],
            ['GET', "/api/v1/organisations/{$id}/audit-logs"],
        ];

        foreach ($requests as $request) {
            [$method, $uri] = $request;
            $response = $this->actingAs($attacker, 'web')->json($method, $uri, $request[2] ?? []);
            $this->assertErrorEnvelope($response, 404, 'not_found');
        }

        $this->assertNotSame('Pwned', $victimOrg->fresh()->name);
        $this->assertSame(Role::ORG_ADMIN, $victimMembership->fresh()->role->slug);
        $this->assertFalse($attacker->membershipIn($victimOrg) !== null);
    }

    public function test_a_membership_id_from_another_tenant_cannot_be_used_through_your_own_tenant(): void
    {
        [$attacker, $attackerOrg] = $this->orgAdmin();
        [, , $victimMembership] = $this->orgAdmin();

        // Attacker is a legitimate admin of their own org, and substitutes a
        // membership ID belonging to the victim org into their own org path.
        $patch = $this->actingAs($attacker, 'web')->patchJson(
            "/api/v1/organisations/{$attackerOrg->id}/members/{$victimMembership->id}",
            ['role' => Role::VIEWER],
        );
        $delete = $this->actingAs($attacker, 'web')->deleteJson(
            "/api/v1/organisations/{$attackerOrg->id}/members/{$victimMembership->id}",
        );

        $this->assertErrorEnvelope($patch, 404, 'not_found');
        $this->assertErrorEnvelope($delete, 404, 'not_found');
        $this->assertModelExists($victimMembership);
        $this->assertSame(Role::ORG_ADMIN, $victimMembership->fresh()->role->slug);
    }

    public function test_audit_logs_only_contain_the_current_tenants_records(): void
    {
        [$adminA, $orgA] = $this->orgAdmin();
        [$adminB, $orgB] = $this->orgAdmin();

        $logger = app(AuditLogger::class);
        $logger->record('test.event.a', organisation: $orgA, actor: $adminA);
        $logger->record('test.event.b', organisation: $orgB, actor: $adminB);
        $logger->record('test.event.platform'); // organisation_id NULL

        $response = $this->actingAs($adminA, 'web')->getJson("/api/v1/organisations/{$orgA->id}/audit-logs?filter[action]=test.");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'test.event.a');
        $this->assertSame(3, AuditLog::withoutTenancy()->where('action', 'like', 'test.%')->count());
    }

    public function test_malformed_organisation_ids_are_not_found_rather_than_errors(): void
    {
        [$user] = $this->orgAdmin();

        foreach (['not-a-uuid', "1' OR '1'='1", '00000000-0000-0000-0000-000000000000'] as $id) {
            $this->assertErrorEnvelope(
                $this->actingAs($user, 'web')->getJson('/api/v1/organisations/'.rawurlencode($id)),
                404,
                'not_found',
            );
        }
    }

    public function test_soft_deleted_organisations_are_inaccessible(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        $organisation->delete();

        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->getJson("/api/v1/organisations/{$organisation->id}"),
            404,
            'not_found',
        );
        $this->actingAs($admin, 'web')->getJson('/api/v1/auth/me')->assertJsonCount(0, 'data.memberships');
    }

    public function test_platform_administrators_have_no_implicit_tenant_access(): void
    {
        [$platformAdmin] = $this->orgAdmin();
        $platformAdmin->forceFill(['platform_role_id' => Role::findBySlug(Role::PLATFORM_ADMIN)->id])->save();
        [, $otherOrg] = $this->orgAdmin();

        $this->assertErrorEnvelope(
            $this->actingAs($platformAdmin, 'web')->getJson("/api/v1/organisations/{$otherOrg->id}/audit-logs"),
            404,
            'not_found',
        );
    }
}
