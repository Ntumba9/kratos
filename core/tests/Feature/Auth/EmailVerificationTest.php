<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    private function verificationUrl(User $user, ?string $hash = null, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }

    public function test_signed_link_verifies_the_email_and_redirects_to_the_app(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user))
            ->assertRedirect('http://localhost:8080/verify-email?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_wrong_email_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user, sha1('someone-else@example.test')))
            ->assertRedirect('http://localhost:8080/verify-email?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_tampered_or_expired_signatures_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user).'x')
            ->assertRedirect('http://localhost:8080/verify-email?status=invalid');

        $expired = $this->verificationUrl($user, minutes: -1);
        $this->get($expired)->assertRedirect('http://localhost:8080/verify-email?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_users_cannot_reach_tenant_resources(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/organisations');

        $this->assertErrorEnvelope($response, 403, 'email_unverified');
    }

    public function test_verification_email_can_be_resent(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertStatus(202);

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_verification_notification_is_queued_on_the_mail_queue(): void
    {
        $notification = new QueuedVerifyEmail;

        $this->assertSame('mail', $notification->queue);
        $this->assertSame(5, $notification->tries);
    }
}
