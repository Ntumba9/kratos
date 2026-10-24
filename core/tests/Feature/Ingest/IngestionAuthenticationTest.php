<?php

namespace Tests\Feature\Ingest;

use App\Domain\Eye\CredentialIssuer;
use App\Domain\Eye\RequestSigner;
use App\Models\ApiCredential;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

class IngestionAuthenticationTest extends TestCase
{
    use SendsSignedEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_correctly_signed_event_is_accepted_into_the_credentials_organisation(): void
    {
        ['credential' => $credential, 'secret' => $secret, 'organisation' => $org] = $this->ingestionClient();

        $response = $this->sendSigned($credential, $secret, $this->eventPayload());

        $response->assertStatus(202)->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.duplicate', false);
        $event = SecurityEvent::withoutTenancy()->findOrFail($response->json('data.id'));
        $this->assertSame($org->id, $event->organisation_id);
        $this->assertSame($credential->id, $event->api_credential_id);
        $this->assertNotNull($credential->fresh()->last_used_at);
    }

    /**
     * @return iterable<string, array{callable(ApiCredential, string): array{0: string, 1: array<string, string>, 2: ?int}}>
     */
    public static function rejectedRequests(): iterable
    {
        yield 'missing signature' => [fn ($c, $s) => [$s, ['X-Kratos-Signature' => ''], null]];
        yield 'wrong secret' => [fn ($c, $s) => ['krs_not_the_secret', [], null]];
        yield 'unknown key' => [fn ($c, $s) => [$s, ['X-Kratos-Key' => 'krk_doesnotexist000000000000'], null]];
        yield 'stale timestamp' => [fn ($c, $s) => [$s, [], time() - 301]];
        yield 'future timestamp' => [fn ($c, $s) => [$s, [], time() + 301]];
        yield 'non-numeric timestamp' => [fn ($c, $s) => [$s, ['X-Kratos-Timestamp' => 'yesterday'], null]];
        yield 'unsupported signature version' => [fn ($c, $s) => [$s, ['X-Kratos-Signature' => 'v0=abc'], null]];
    }

    #[DataProvider('rejectedRequests')]
    public function test_bad_authentication_is_rejected_identically(callable $variant): void
    {
        ['credential' => $credential, 'secret' => $secret] = $this->ingestionClient();
        [$signingSecret, $headers, $timestamp] = $variant($credential, $secret);

        $response = $this->sendSigned($credential, $signingSecret, $this->eventPayload(), headers: $headers, timestamp: $timestamp);

        $this->assertErrorEnvelope($response, 401, 'invalid_signature');
        $this->assertSame(0, SecurityEvent::withoutTenancy()->count());
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        ['credential' => $credential, 'secret' => $secret] = $this->ingestionClient();
        $signedFor = json_encode($this->eventPayload());
        $sent = json_encode($this->eventPayload(['severity' => 'critical']));

        // Sign one body, send another.
        $timestamp = time();
        $signature = RequestSigner::sign($secret, 'POST', '/api/v1/ingest/events', (string) $timestamp, $signedFor);
        $response = $this->sendSigned($credential, $secret, $sent, headers: ['X-Kratos-Signature' => $signature], timestamp: $timestamp);

        $this->assertErrorEnvelope($response, 401, 'invalid_signature');
    }

    public function test_revoked_and_expired_credentials_are_rejected(): void
    {
        ['credential' => $credential, 'secret' => $secret] = $this->ingestionClient();
        app(CredentialIssuer::class)->revoke($credential);

        $this->assertErrorEnvelope($this->sendSigned($credential, $secret, $this->eventPayload()), 401, 'invalid_signature');

        ['credential' => $expiring, 'secret' => $expiringSecret] = $this->ingestionClient();
        $expiring->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertErrorEnvelope($this->sendSigned($expiring, $expiringSecret, $this->eventPayload()), 401, 'invalid_signature');
    }

    public function test_the_secret_is_stored_encrypted_and_never_serialised(): void
    {
        ['credential' => $credential, 'secret' => $secret] = $this->ingestionClient();

        $raw = DB::table('api_credentials')->where('id', $credential->id)->value('secret_encrypted');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, app(CredentialIssuer::class)->secretFor($credential->fresh()));
        $this->assertArrayNotHasKey('secret_encrypted', $credential->fresh()->toArray());
    }

    public function test_user_sessions_and_tokens_cannot_be_used_for_ingestion(): void
    {
        [$admin] = $this->orgAdmin();

        $response = $this->actingAs($admin, 'web')->postJson('/api/v1/ingest/events', $this->eventPayload());

        $this->assertErrorEnvelope($response, 401, 'invalid_signature');
    }

    public function test_ingestion_uses_its_own_per_credential_rate_limit(): void
    {
        config(['kratos.ingestion.rate_limit_per_minute' => 2]);
        ['credential' => $credential, 'secret' => $secret] = $this->ingestionClient();

        $first = $this->sendSigned($credential, $secret, $this->eventPayload(['metadata' => ['failed_attempts' => 1]]));
        $first->assertStatus(202)->assertHeader('X-RateLimit-Limit', '2');
        $this->sendSigned($credential, $secret, $this->eventPayload(['metadata' => ['failed_attempts' => 2]]))->assertStatus(202);

        $limited = $this->sendSigned($credential, $secret, $this->eventPayload(['metadata' => ['failed_attempts' => 3]]));
        $this->assertErrorEnvelope($limited, 429, 'rate_limited');

        // A different credential has its own budget.
        ['credential' => $other, 'secret' => $otherSecret] = $this->ingestionClient();
        $this->sendSigned($other, $otherSecret, $this->eventPayload())->assertStatus(202);
    }
}
