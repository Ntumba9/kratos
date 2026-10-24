<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Analyst',
            'email' => 'Ada@Example.test',
            'password' => 'Correct-Horse-42',
            'password_confirmation' => 'Correct-Horse-42',
            'organisation_name' => 'Northwind Security',
        ], $overrides);
    }

    public function test_registration_creates_user_organisation_and_admin_membership(): void
    {
        Notification::fake();

        $response = $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.test')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.memberships.0.organisation.name', 'Northwind Security')
            ->assertJsonPath('data.memberships.0.role.slug', Role::ORG_ADMIN);

        $user = User::query()->where('email', 'ada@example.test')->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame('Correct-Horse-42', $user->password);
        $this->assertContains('members.manage', $response->json('data.memberships.0.permissions'));

        Notification::assertSentTo($user, QueuedVerifyEmail::class);

        $this->assertTrue(AuditLog::withoutTenancy()->where('action', 'auth.registered')->where('actor_user_id', $user->id)->exists());
        $this->assertTrue(AuditLog::withoutTenancy()->where('action', 'organisation.created')->exists());
    }

    public function test_email_uniqueness_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'ada@example.test']);

        $response = $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload(['email' => 'ADA@EXAMPLE.TEST']));

        $this->assertErrorEnvelope($response, 422, 'validation_failed');
        $response->assertJsonValidationErrors(['email'], 'error.details');
    }

    public function test_weak_passwords_are_rejected(): void
    {
        foreach (['short1A', 'alllowercase-123', 'NoDigitsHereAtAll'] as $weak) {
            $response = $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload([
                'password' => $weak,
                'password_confirmation' => $weak,
            ]));

            $response->assertStatus(422)->assertJsonValidationErrors(['password'], 'error.details');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_malformed_payload_returns_structured_validation_errors(): void
    {
        $response = $this->fromSpa()->postJson('/api/v1/auth/register', ['email' => 'not-an-email']);

        $this->assertErrorEnvelope($response, 422, 'validation_failed');
        $response->assertJsonValidationErrors(['name', 'email', 'password', 'organisation_name'], 'error.details');
    }

    public function test_registration_requires_a_first_party_browser_request(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $this->assertErrorEnvelope($response, 400, 'stateful_request_required');
        $this->assertDatabaseCount('users', 0);
    }
}
