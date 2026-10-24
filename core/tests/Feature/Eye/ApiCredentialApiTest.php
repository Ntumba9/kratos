<?php

namespace Tests\Feature\Eye;

use App\Models\ApiCredential;
use App\Models\Role;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

class ApiCredentialApiTest extends TestCase
{
    use SendsSignedEvents;

    public function test_admins_create_credentials_and_see_the_secret_exactly_once(): void
    {
        Queue::fake();
        [$admin, $org] = $this->orgAdmin();
        $base = "/api/v1/organisations/{$org->id}/api-credentials";

        $created = $this->actingAs($admin, 'web')->postJson($base, ['name' => 'web tier', 'expires_in_days' => 30]);

        $created->assertCreated()
            ->assertJsonPath('data.credential.status', 'active')
            ->assertJsonPath('data.credential.scopes', ['events:ingest']);
        $secret = $created->json('data.secret');
        $this->assertStringStartsWith('krs_', $secret);
        $this->assertStringStartsWith('krk_', $created->json('data.credential.key_id'));

        $listing = $this->actingAs($admin, 'web')->getJson($base);
        $listing->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString($secret, $listing->getContent());
        $this->assertStringNotContainsString('secret', $listing->getContent());

        // The issued pair really works for signed ingestion.
        $credential = ApiCredential::withoutTenancy()->findOrFail($created->json('data.credential.id'));
        $this->sendSigned($credential, $secret, $this->eventPayload())->assertStatus(202);
    }

    public function test_revoked_credentials_stop_working_immediately(): void
    {
        Queue::fake();
        [$admin, $org] = $this->orgAdmin();
        ['credential' => $credential, 'secret' => $secret] = $this->ingestionClient($org);

        $this->actingAs($admin, 'web')->deleteJson("/api/v1/organisations/{$org->id}/api-credentials/{$credential->id}")->assertNoContent();

        $this->assertSame('revoked', $credential->fresh()->status());
        $this->assertErrorEnvelope($this->sendSigned($credential, $secret, $this->eventPayload()), 401, 'invalid_signature');
    }

    public function test_only_organisation_admins_manage_credentials(): void
    {
        [, $org] = $this->orgAdmin();
        [$analyst] = $this->memberWithRole(Role::ANALYST, $org);

        $this->assertErrorEnvelope($this->actingAs($analyst, 'web')->getJson("/api/v1/organisations/{$org->id}/api-credentials"), 403, 'forbidden');
        $this->assertErrorEnvelope($this->actingAs($analyst, 'web')->postJson("/api/v1/organisations/{$org->id}/api-credentials", ['name' => 'x1']), 403, 'forbidden');
    }

    public function test_credentials_of_other_organisations_cannot_be_revoked(): void
    {
        [$admin, $org] = $this->orgAdmin();
        ['credential' => $foreign] = $this->ingestionClient();

        $this->assertErrorEnvelope(
            $this->actingAs($admin, 'web')->deleteJson("/api/v1/organisations/{$org->id}/api-credentials/{$foreign->id}"),
            404,
            'not_found',
        );
        $this->assertNull($foreign->fresh()->revoked_at);
    }
}
