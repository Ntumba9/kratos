<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    private function insertSession(User $user, string $id, string $agent = 'Mozilla/5.0'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.10',
            'user_agent' => $agent,
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_users_see_only_their_own_sessions_and_never_raw_session_ids(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->insertSession($user, str_repeat('a', 40), 'Laptop');
        $this->insertSession($user, str_repeat('b', 40), 'Phone');
        $this->insertSession($other, str_repeat('c', 40), 'Other');

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/auth/sessions');

        $response->assertOk()->assertJsonCount(2, 'data');
        $body = $response->getContent();
        $this->assertStringNotContainsString(str_repeat('a', 40), $body);
        $this->assertStringNotContainsString('Other', $body);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('data.0.id'));
    }

    public function test_a_session_can_be_revoked_by_its_handle(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, str_repeat('a', 40));

        $handle = $this->actingAs($user, 'web')->getJson('/api/v1/auth/sessions')->json('data.0.id');

        $this->actingAs($user, 'web')->deleteJson('/api/v1/auth/sessions/'.$handle)->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => str_repeat('a', 40)]);
    }

    public function test_another_users_session_cannot_be_revoked(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $this->insertSession($victim, str_repeat('v', 40));
        $handle = hash_hmac('sha256', str_repeat('v', 40), (string) config('app.key'));

        $this->assertErrorEnvelope(
            $this->actingAs($user, 'web')->deleteJson('/api/v1/auth/sessions/'.$handle),
            404,
            'not_found',
        );
        $this->assertDatabaseHas('sessions', ['id' => str_repeat('v', 40)]);
    }

    public function test_all_other_sessions_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, str_repeat('a', 40));
        $this->insertSession($user, str_repeat('b', 40));

        $this->actingAs($user, 'web')->deleteJson('/api/v1/auth/sessions')->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }
}
