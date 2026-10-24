<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_reset_link_response_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());

        Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:8080/reset-password?token=');
        });
        Notification::assertCount(1);
    }

    public function test_valid_token_resets_the_password_and_ends_existing_sessions(): void
    {
        $user = User::factory()->create(['email' => 'known@example.test']);
        $token = Password::broker()->createToken($user);
        DB::table('sessions')->insert([
            'id' => str_repeat('a', 40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'known@example.test',
            'password' => 'Brand-New-Secret-9',
            'password_confirmation' => 'Brand-New-Secret-9',
        ])->assertOk();

        $this->assertTrue(Hash::check('Brand-New-Secret-9', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'known@example.test']);
        $original = $user->password;

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'known@example.test',
            'password' => 'Brand-New-Secret-9',
            'password_confirmation' => 'Brand-New-Secret-9',
        ]);

        $this->assertErrorEnvelope($response, 422, 'invalid_reset_token');
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_authenticated_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Current-Secret-1')]);

        $this->actingAs($user, 'web')->putJson('/api/v1/auth/password', [
            'current_password' => 'not-it',
            'password' => 'Brand-New-Secret-9',
            'password_confirmation' => 'Brand-New-Secret-9',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password'], 'error.details');

        $this->actingAs($user, 'web')->putJson('/api/v1/auth/password', [
            'current_password' => 'Current-Secret-1',
            'password' => 'Brand-New-Secret-9',
            'password_confirmation' => 'Brand-New-Secret-9',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('Brand-New-Secret-9', $user->fresh()->password));
    }
}
