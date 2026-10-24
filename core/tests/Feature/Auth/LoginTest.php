<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'analyst@example.test',
            'password' => Hash::make('Correct-Horse-42'),
        ]);
    }

    public function test_valid_credentials_start_a_session(): void
    {
        $response = $this->fromSpa()->postJson('/api/v1/auth/login', [
            'email' => 'Analyst@Example.test',
            'password' => 'Correct-Horse-42',
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $this->user->id);
        $this->assertAuthenticatedAs($this->user, 'web');
        $this->assertNotNull($this->user->fresh()->last_login_at);
        $this->assertTrue(AuditLog::withoutTenancy()->where('action', 'auth.login.succeeded')->exists());
    }

    public function test_invalid_credentials_are_rejected_and_audited_without_the_password(): void
    {
        $response = $this->fromSpa()->postJson('/api/v1/auth/login', [
            'email' => 'analyst@example.test',
            'password' => 'Wrong-Password-1',
        ]);

        $this->assertErrorEnvelope($response, 401, 'invalid_credentials');
        $this->assertGuest('web');

        $entry = AuditLog::withoutTenancy()->where('action', 'auth.login.failed')->sole();
        $this->assertSame('analyst@example.test', $entry->metadata['email']);
        $this->assertTrue($entry->metadata['known_user']);
        $this->assertStringNotContainsString('Wrong-Password-1', json_encode($entry->getAttributes()));
    }

    public function test_unknown_email_gets_the_same_response_as_a_wrong_password(): void
    {
        $unknown = $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'Whatever-123']);
        $wrong = $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'analyst@example.test', 'password' => 'Whatever-123']);

        $this->assertSame($unknown->status(), $wrong->status());
        $this->assertSame($unknown->json('error'), $wrong->json('error'));
    }

    public function test_login_is_rate_limited_per_identifier(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'analyst@example.test', 'password' => 'Wrong-'.$i])
                ->assertStatus(401);
        }

        $response = $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'analyst@example.test', 'password' => 'Correct-Horse-42']);

        $this->assertErrorEnvelope($response, 429, 'rate_limited');
        $response->assertHeader('Retry-After');
        $this->assertGuest('web');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->user, 'web');

        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_me_requires_authentication(): void
    {
        $this->assertErrorEnvelope($this->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function test_me_returns_the_current_principal(): void
    {
        [$user, $organisation] = $this->orgAdmin();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.platform_role', null)
            ->assertJsonPath('data.memberships.0.organisation.id', $organisation->id)
            ->assertJsonMissingPath('data.user.password');
    }
}
