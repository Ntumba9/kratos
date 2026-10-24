<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rate limiting whose behaviour during a limiter-store (Redis) outage is an
 * explicit decision per limiter rather than an accidental HTTP 500:
 *
 * - FAIL_OPEN limiters let the request through. Used for ingestion: telemetry
 *   is persisted to PostgreSQL via the outbox and must not be rejected
 *   because Redis is briefly unavailable. Those requests are still HMAC
 *   authenticated and size limited.
 * - All other limiters fail closed with 503 — notably the credential
 *   endpoints, whose brute-force protection must not silently disappear.
 *
 * Bound in place of Laravel's ThrottleRequests (AppServiceProvider).
 */
class ResilientThrottleRequests extends ThrottleRequests
{
    public const FAIL_OPEN = ['ingest'];

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        try {
            // Forward the original argument list: the parent only resolves a
            // *named* limiter when it receives exactly three arguments.
            return parent::handle(...func_get_args());
        } catch (ThrottleRequestsException $e) {
            throw $e; // a genuine 429
        } catch (Throwable $e) {
            if (! $this->isLimiterStoreFailure($e)) {
                throw $e; // raised by the downstream application, not the limiter
            }

            $limiter = is_string($maxAttempts) ? $maxAttempts : 'inline';
            Log::error('rate_limiter.store_unavailable', [
                'limiter' => $limiter,
                'fail_open' => in_array($limiter, self::FAIL_OPEN, true),
                'exception' => $e::class,
            ]);

            if (in_array($limiter, self::FAIL_OPEN, true)) {
                return $next($request);
            }

            return ApiResponse::error('service_unavailable', 'A required service is temporarily unavailable. Retry shortly.', 503, headers: ['Retry-After' => '30']);
        }
    }

    /**
     * Only connection-level failures of the cache/Redis layer count as a
     * limiter outage. (Exceptions from the wrapped controller never get here:
     * the routing pipeline renders them into responses first.)
     */
    private function isLimiterStoreFailure(Throwable $e): bool
    {
        if ($e instanceof \RedisException) {
            return true;
        }

        foreach (array_slice($e->getTrace(), 0, 40) as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'Illuminate\\Cache\\') || str_starts_with($class, 'Illuminate\\Redis\\')) {
                return true;
            }
        }

        return false;
    }
}
