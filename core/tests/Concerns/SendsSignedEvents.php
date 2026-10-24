<?php

namespace Tests\Concerns;

use App\Domain\Eye\CredentialIssuer;
use App\Domain\Eye\RequestSigner;
use App\Models\ApiCredential;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for exercising the ingestion API exactly as an external client
 * would: real credential, real HMAC signature over the raw body.
 */
trait SendsSignedEvents
{
    /**
     * @return array{credential: ApiCredential, secret: string, organisation: Organisation}
     */
    protected function ingestionClient(?Organisation $organisation = null): array
    {
        $organisation ??= Organisation::factory()->create();
        $issued = app(CredentialIssuer::class)->issue($organisation, 'test client', null, User::factory()->create());

        return $issued + ['organisation' => $organisation];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function eventPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event_type' => 'authentication_failure',
            'source' => 'application_server',
            'severity' => 'medium',
            'timestamp' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
            'metadata' => ['failed_attempts' => 12, 'source_ip' => '192.0.2.10'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|string  $body  array is JSON-encoded; string is sent raw
     * @param  array<string, string>  $headers  extra/override headers
     */
    protected function sendSigned(
        ApiCredential $credential,
        string $secret,
        array|string $body,
        string $path = '/api/v1/ingest/events',
        array $headers = [],
        ?int $timestamp = null,
    ): TestResponse {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($timestamp ?? time());

        $all = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Kratos-Key' => $credential->key_id,
            'X-Kratos-Timestamp' => $timestamp,
            'X-Kratos-Signature' => RequestSigner::sign($secret, 'POST', $path, $timestamp, $raw),
        ], $headers);

        $server = [];
        foreach ($all as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        // The auth middleware sets a tenant context; reset guards between calls.
        $this->app['auth']->forgetGuards();

        return $this->call('POST', $path, [], [], [], $server, $raw);
    }
}
