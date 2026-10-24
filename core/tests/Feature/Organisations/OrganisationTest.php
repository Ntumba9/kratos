<?php

namespace Tests\Feature\Organisations;

use App\Models\AuditLog;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class OrganisationTest extends TestCase
{
    public function test_users_list_only_organisations_they_belong_to(): void
    {
        [$user, $mine] = $this->memberWithRole(Role::VIEWER);
        Organisation::factory()->count(2)->create();

        $this->actingAs($user, 'web')->getJson('/api/v1/organisations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_creating_an_organisation_makes_the_creator_its_administrator(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/organisations', ['name' => 'Blue Team Ltd']);

        $response->assertCreated()->assertJsonPath('data.slug', 'blue-team-ltd');
        $membership = $user->membershipIn($response->json('data.id'));
        $this->assertSame(Role::ORG_ADMIN, $membership->role->slug);
    }

    public function test_slugs_stay_unique(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user, 'web')->postJson('/api/v1/organisations', ['name' => 'Same Name'])->json('data.slug');
        $second = $this->actingAs($user, 'web')->postJson('/api/v1/organisations', ['name' => 'Same Name'])->json('data.slug');

        $this->assertSame('same-name', $first);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^same-name-[a-z0-9]{6}$/', $second);
    }

    public function test_members_can_view_their_organisation(): void
    {
        [$viewer, $organisation] = $this->memberWithRole(Role::VIEWER);

        $this->actingAs($viewer, 'web')->getJson("/api/v1/organisations/{$organisation->id}")
            ->assertOk()
            ->assertJsonPath('data.name', $organisation->name);
    }

    public function test_only_organisation_admins_can_update_settings(): void
    {
        [$admin, $organisation] = $this->orgAdmin();
        [$analyst] = $this->memberWithRole(Role::ANALYST, $organisation);

        $this->assertErrorEnvelope(
            $this->actingAs($analyst, 'web')->patchJson("/api/v1/organisations/{$organisation->id}", ['name' => 'Hijacked']),
            403,
            'forbidden',
        );

        $this->actingAs($admin, 'web')->patchJson("/api/v1/organisations/{$organisation->id}", [
            'name' => 'Renamed Org',
            'event_retention_days' => 30,
        ])->assertOk()->assertJsonPath('data.event_retention_days', 30);

        $this->assertSame('Renamed Org', $organisation->fresh()->name);

        $audit = AuditLog::withoutTenancy()->where('action', 'organisation.updated')->sole();
        $this->assertSame($organisation->id, $audit->organisation_id);
        $this->assertSame('Renamed Org', $audit->metadata['after']['name']);
    }

    public function test_settings_are_validated(): void
    {
        [$admin, $organisation] = $this->orgAdmin();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/organisations/{$organisation->id}", ['event_retention_days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event_retention_days'], 'error.details');
    }
}
