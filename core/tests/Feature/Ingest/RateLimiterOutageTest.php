<?php

namespace Tests\Feature\Ingest;

use App\Models\SecurityEvent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RedisException;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;

/**
 * Regression for a defect found in a live outage drill: with Redis down, the
 * rate limiter threw and ingestion returned 500 even though the outbox would
 * have stored the event safely. Behaviour is now explicit per limiter.
 */
class RateLimiterOutageTest extends TestCase
{
    use SendsSignedEvents;

    private function limiterStoreDown(): void
    {
        // Proxy the real limiter (keeps the registered named limiters) but make
        // every store access fail like an unreachable Redis.
        $real = $this->app->make(RateLimiter::class);
        $broken = Mockery::mock($real);
        $broken->shouldReceive('tooManyAttempts', 'hit', 'attempts', 'remaining', 'availableIn')
            ->andThrow(new RedisException('Connection refused'));
        $this->app->instance(RateLimiter::class, $broken);
    }

    public function test_ingestion_fails_open_and_still_stores_the_event(): void
    {
        Queue::fake();
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $this->limiterStoreDown();

        $response = $this->sendSigned($c, $s, $this->eventPayload());

        $response->assertStatus(202);
        $this->assertSame(1, SecurityEvent::withoutTenancy()->count());
    }

    public function test_credential_endpoints_fail_closed_with_503(): void
    {
        $this->limiterStoreDown();

        $response = $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'whatever-123A']);

        $this->assertErrorEnvelope($response, 503, 'service_unavailable');
        $response->assertHeader('Retry-After', '30');
    }

    public function test_genuine_rate_limiting_still_returns_429(): void
    {
        config(['kratos.ingestion.rate_limit_per_minute' => 1]);
        Queue::fake();
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();

        $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['failed_attempts' => 1]]))->assertStatus(202);
        $this->assertErrorEnvelope(
            $this->sendSigned($c, $s, $this->eventPayload(['metadata' => ['failed_attempts' => 2]])),
            429,
            'rate_limited',
        );
    }
}
