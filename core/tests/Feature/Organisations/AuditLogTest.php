<?php

namespace Tests\Feature\Organisations;

use App\Domain\Audit\AuditLogger;
use App\Models\Role;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_admins_can_read_and_filter_the_audit_log(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        $logger = app(AuditLogger::class);
        $logger->record('membership.created', organisation: $organisation, actor: $admin);
        $logger->record('membership.removed', organisation: $organisation, actor: $admin);
        $logger->record('organisation.updated', organisation: $organisation, actor: $admin);

        $response = $this->actingAs($admin, 'web')
            ->getJson("/api/v1/organisations/{$organisation->id}/audit-logs?filter[action]=membership.&sort=action");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'membership.created')
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_analysts_and_viewers_cannot_read_the_audit_log(): void
    {
        [, $organisation] = $this->orgAdmin();

        foreach ([Role::ANALYST, Role::VIEWER] as $role) {
            [$user] = $this->memberWithRole($role, $organisation);

            $this->assertErrorEnvelope(
                $this->actingAs($user, 'web')->getJson("/api/v1/organisations/{$organisation->id}/audit-logs"),
                403,
                'forbidden',
            );
        }
    }

    public function test_unknown_sort_columns_and_bad_filters_are_rejected(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        $base = "/api/v1/organisations/{$organisation->id}/audit-logs";

        $this->actingAs($admin, 'web')->getJson($base.'?sort=password')
            ->assertStatus(422)->assertJsonValidationErrors(['sort'], 'error.details');

        $this->actingAs($admin, 'web')->getJson($base.'?filter[action]='.urlencode("x'; DROP TABLE users;--"))
            ->assertStatus(422)->assertJsonValidationErrors(['filter.action'], 'error.details');

        $this->actingAs($admin, 'web')->getJson($base.'?per_page=1000')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page'], 'error.details');
    }

    public function test_like_wildcards_in_the_action_filter_are_literal(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        app(AuditLogger::class)->record('membership.created', organisation: $organisation, actor: $admin);

        // "_" would match any character if passed through unescaped.
        $this->actingAs($admin, 'web')
            ->getJson("/api/v1/organisations/{$organisation->id}/audit-logs?filter[action]=membership_")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
