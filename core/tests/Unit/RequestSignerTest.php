<?php

namespace Tests\Unit;

use App\Domain\Eye\RequestSigner;
use PHPUnit\Framework\TestCase;

/**
 * Shared test vector with tools/kratos-send/test_kratos_send.py. If either
 * implementation changes the canonical form, both tests fail.
 */
class RequestSignerTest extends TestCase
{
    public const SECRET = 'krs_test_vector_secret_do_not_use';

    public const BODY = '{"event_type":"authentication_failure","severity":"medium"}';

    public const EXPECTED = 'v1=eba2a321c0723800bff9b21f8252247d8438e4195cf19711f3fe105aa00f2b1d';

    public function test_canonical_form(): void
    {
        $this->assertSame(
            "POST\n/api/v1/ingest/events\n1792771200\n".hash('sha256', self::BODY),
            RequestSigner::canonical('post', '/api/v1/ingest/events', '1792771200', self::BODY),
        );
    }

    public function test_shared_vector(): void
    {
        $this->assertSame(self::EXPECTED, RequestSigner::sign(self::SECRET, 'POST', '/api/v1/ingest/events', '1792771200', self::BODY));
    }

    public function test_verification_rejects_any_change(): void
    {
        $sig = RequestSigner::sign(self::SECRET, 'POST', '/api/v1/ingest/events', '1792771200', self::BODY);

        $this->assertTrue(RequestSigner::verify(self::SECRET, 'POST', '/api/v1/ingest/events', '1792771200', self::BODY, $sig));
        $this->assertFalse(RequestSigner::verify(self::SECRET, 'POST', '/api/v1/ingest/events', '1792771201', self::BODY, $sig));
        $this->assertFalse(RequestSigner::verify(self::SECRET, 'POST', '/api/v1/ingest/events/batch', '1792771200', self::BODY, $sig));
        $this->assertFalse(RequestSigner::verify(self::SECRET, 'POST', '/api/v1/ingest/events', '1792771200', self::BODY.' ', $sig));
        $this->assertFalse(RequestSigner::verify('other', 'POST', '/api/v1/ingest/events', '1792771200', self::BODY, $sig));
    }
}
