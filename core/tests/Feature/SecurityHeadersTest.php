<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_request_ids_are_generated_or_propagated(): void
    {
        $generated = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generated);

        $this->withHeader('X-Request-Id', 'trace-abc-12345')->getJson('/api/v1/health')
            ->assertHeader('X-Request-Id', 'trace-abc-12345');

        // Header injection / oversized values are replaced, not echoed.
        $replaced = $this->withHeader('X-Request-Id', str_repeat('x', 200))->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $this->assertNotSame(str_repeat('x', 200), $replaced);
    }

    public function test_foreign_origins_receive_no_cors_grant(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/v1/health')
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_error_bodies_include_the_request_id(): void
    {
        $response = $this->withHeader('X-Request-Id', 'trace-err-12345')->getJson('/api/v1/auth/me');

        $response->assertStatus(401)->assertJsonPath('request_id', 'trace-err-12345');
    }
}
