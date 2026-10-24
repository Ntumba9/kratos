<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HealthAndResilienceTest extends TestCase
{
    public function test_health_reports_dependencies_up(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database.status', 'up')
            ->assertJsonPath('data.checks.redis.status', 'up');
    }

    public function test_redis_outage_is_reported_as_degraded_without_leaking_details(): void
    {
        config([
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1, // nothing listens here
            'database.redis.default.timeout' => 0.5,
        ]);
        Redis::purge('default');

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.redis.status', 'down')
            ->assertJsonPath('data.checks.database.status', 'up');

        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
        $this->assertStringNotContainsString('Connection refused', $response->getContent());
    }

    public function test_unknown_api_routes_use_the_error_envelope(): void
    {
        $this->assertErrorEnvelope($this->getJson('/api/v1/does-not-exist'), 404, 'not_found');
    }

    public function test_wrong_http_method_uses_the_error_envelope(): void
    {
        $this->assertErrorEnvelope($this->deleteJson('/api/v1/health'), 405, 'method_not_allowed');
    }

    public function test_unhandled_errors_do_not_leak_internals_when_debug_is_off(): void
    {
        Route::get('/api/v1/_boom', fn () => throw new \RuntimeException('secret internal detail'));

        $response = $this->getJson('/api/v1/_boom');

        $this->assertErrorEnvelope($response, 500, 'internal_error');
        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
        $response->assertJsonMissingPath('error.details');
    }
}
