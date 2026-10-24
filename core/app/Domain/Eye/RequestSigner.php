<?php

namespace App\Domain\Eye;

/**
 * KRATOS request signature scheme v1 (ADR-0006).
 *
 *   canonical = METHOD "\n" PATH "\n" TIMESTAMP "\n" hex(sha256(body))
 *   signature = "v1=" hex(hmac_sha256(secret, canonical))
 *
 * The same algorithm is implemented by tools/kratos-send (Python); the shared
 * test vector in tests/Unit/RequestSignerTest.php keeps them compatible.
 */
final class RequestSigner
{
    public const VERSION = 'v1';

    public static function canonical(string $method, string $path, string $timestamp, string $body): string
    {
        return strtoupper($method)."\n".$path."\n".$timestamp."\n".hash('sha256', $body);
    }

    public static function sign(#[\SensitiveParameter] string $secret, string $method, string $path, string $timestamp, string $body): string
    {
        return self::VERSION.'='.hash_hmac('sha256', self::canonical($method, $path, $timestamp, $body), $secret);
    }

    public static function verify(#[\SensitiveParameter] string $secret, string $method, string $path, string $timestamp, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $method, $path, $timestamp, $body), $signature);
    }
}
