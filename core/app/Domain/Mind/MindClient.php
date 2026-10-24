<?php

namespace App\Domain\Mind;

use App\Domain\Eye\RequestSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Signed HTTP client for KRATOS MIND (ADR-0007).
 *
 *  - Connection failures, timeouts and 5xx ⇒ MindUnavailable (transient: the
 *    job waits and retries without spending an attempt).
 *  - 4xx ⇒ MindRejected (the request violates the contract: a bug, which
 *    counts as a failed attempt and eventually dead-letters).
 */
class MindClient
{
    public const ANALYSE_PATH = '/internal/v1/analyse';

    public const MODELS_PATH = '/internal/v1/models/active';

    public function __construct(private readonly Http $http) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function analyse(array $payload): array
    {
        return $this->send('POST', self::ANALYSE_PATH, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{metadata: array<string, mixed>, model_card: string|null}
     */
    public function activeModel(): array
    {
        return $this->send('GET', self::MODELS_PATH, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, string $body): array
    {
        $secret = (string) config('kratos.mind.shared_secret');
        if ($secret === '') {
            throw new MindUnavailable('MIND_SHARED_SECRET is not configured.');
        }

        $timestamp = (string) time();
        $request = $this->http
            ->baseUrl((string) config('kratos.mind.url'))
            ->timeout((float) config('kratos.mind.timeout_seconds'))
            ->connectTimeout((float) config('kratos.mind.connect_timeout_seconds'))
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Kratos-Service' => 'core',
                'X-Kratos-Timestamp' => $timestamp,
                'X-Kratos-Signature' => RequestSigner::sign($secret, $method, $path, $timestamp, $body),
                'X-Request-Id' => (string) (request()->attributes->get('request_id') ?? Str::uuid7()),
            ]);

        try {
            /** @var Response $response */
            $response = $method === 'GET'
                ? $request->get($path)
                : $request->withBody($body, 'application/json')->post($path);
        } catch (ConnectionException $e) {
            throw new MindUnavailable('KRATOS MIND is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->serverError()) {
            throw new MindUnavailable("KRATOS MIND returned HTTP {$response->status()}.");
        }

        if ($response->clientError()) {
            Log::error('mind.request_rejected', ['status' => $response->status(), 'error' => $response->json('error')]);
            throw new MindRejected("KRATOS MIND rejected the request (HTTP {$response->status()}): ".json_encode($response->json('error')));
        }

        return $response->json() ?? throw new MindUnavailable('KRATOS MIND returned a non-JSON response.');
    }
}
