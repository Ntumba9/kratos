<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness probe for dependencies. Public, so it reports only up/down and
 * latency — never versions, hostnames or error messages.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe('database', fn () => DB::select('SELECT 1')),
            'redis' => $this->probe('redis', fn () => Redis::connection()->ping()),
            // Ingestion keeps working without MIND (events wait for analysis), so
            // its absence makes the platform degraded rather than down.
            'mind' => $this->probe('mind', fn () => Http::timeout(2)->connectTimeout(1)
                ->get(config('kratos.mind.url').'/health')->throw()),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'up');

        return new JsonResponse([
            'data' => [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'checked_at' => now()->toIso8601String(),
            ],
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{status: string, latency_ms: float|null}
     */
    private function probe(string $name, callable $check): array
    {
        $started = hrtime(true);

        try {
            $check();

            return ['status' => 'up', 'latency_ms' => round((hrtime(true) - $started) / 1e6, 2)];
        } catch (Throwable $e) {
            Log::warning('health.check_failed', ['dependency' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return ['status' => 'down', 'latency_ms' => null];
        }
    }
}
