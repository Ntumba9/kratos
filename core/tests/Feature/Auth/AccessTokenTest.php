<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AccessTokenTest extends TestCase
{
    public function test_token_is_shown_once_and_stored_only_as_a_digest(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/auth/tokens', [
            'name' => 'CI pipeline',
            'abilities' => ['read'],
            'expires_in_days' => 30,
        ]);

        $response->assertCreated()->assertJsonPath('data.token.abilities', ['read']);
        $plain = $response->json('data.plain_text_token');
        $this->assertStringStartsWith('kratos_pat_', explode('|', $plain)[1]);

        $stored = PersonalAccessToken::query()->sole();
        $this->assertStringNotContainsString($stored->token, $plain);
        $this->assertSame(hash('sha256', explode('|', $plain)[1]), $stored->token);
        $this->assertTrue($stored->expires_at->between(now()->addDays(29), now()->addDays(31)));

        // Listing never re-exposes the secret.
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.plain_text_token')
            ->assertJsonMissingPath('data.0.token');
    }

    public function test_bearer_token_authenticates_and_abilities_are_enforced(): void
    {
        $user = User::factory()->create();
        $readOnly = $user->createToken('read-only', ['read'], now()->addDay())->plainTextToken;

        $this->withToken($readOnly)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $response = $this->withToken($readOnly)->postJson('/api/v1/organisations', ['name' => 'Should Not Exist']);

        $this->assertErrorEnvelope($response, 403, 'token_ability_missing');
        $this->assertDatabaseMissing('organisations', ['name' => 'Should Not Exist']);
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('old', ['read'], now()->subMinute())->plainTextToken;

        $this->assertErrorEnvelope($this->withToken($token)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function test_revoked_tokens_stop_working(): void
    {
        $user = User::factory()->create();
        $new = $user->createToken('temp', ['read', 'write'], now()->addDay());

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/auth/tokens/'.$new->accessToken->id)
            ->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->assertErrorEnvelope($this->withToken($new->plainTextToken)->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function test_users_cannot_revoke_each_others_tokens(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $token = $owner->createToken('victim', ['read'], now()->addDay());

        $response = $this->actingAs($attacker, 'web')->deleteJson('/api/v1/auth/tokens/'.$token->accessToken->id);

        $this->assertErrorEnvelope($response, 404, 'not_found');
        $this->assertModelExists($token->accessToken);
    }

    public function test_token_requests_are_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/v1/auth/tokens', [
            'name' => 'x',
            'abilities' => ['admin'],
            'expires_in_days' => 9999,
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'abilities.0', 'expires_in_days'], 'error.details');
    }
}
