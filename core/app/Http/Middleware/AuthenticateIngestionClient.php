<?php

namespace App\Http\Middleware;

use App\Domain\Eye\CredentialIssuer;
use App\Domain\Eye\RequestSigner;
use App\Domain\Tenancy\TenantContext;
use App\Http\Responses\ApiResponse;
use App\Models\ApiCredential;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates ingestion clients by HMAC request signature (ADR-0006) and
 * binds the credential's organisation as the tenant. Every failure mode
 * returns the same 401, so callers cannot distinguish an unknown key from a
 * revoked one or a bad signature.
 *
 * Implements AuthenticatesRequests so Laravel's middleware priority places it
 * before ThrottleRequests: the per-credential rate limit needs the credential.
 */
class AuthenticateIngestionClient implements AuthenticatesRequests
{
    public const CREDENTIAL_ATTRIBUTE = 'ingestion_credential';

    /** Avoid a database write per request just to record usage. */
    private const LAST_USED_RESOLUTION_SECONDS = 60;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CredentialIssuer $issuer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->headers->get('X-Kratos-Key', '');
        $timestamp = (string) $request->headers->get('X-Kratos-Timestamp', '');
        $signature = (string) $request->headers->get('X-Kratos-Signature', '');

        if ($keyId === '' || $timestamp === '' || $signature === '') {
            return $this->reject('missing_headers', $keyId);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > config('kratos.ingestion.signature_tolerance_seconds')) {
            return $this->reject('timestamp_out_of_window', $keyId);
        }

        // Credential lookup is necessarily cross-tenant: the key identifies the tenant.
        $credential = ApiCredential::withoutTenancy()->with('organisation')->where('key_id', $keyId)->first();

        if ($credential === null || ! $credential->isUsable() || ! $credential->hasScope(ApiCredential::SCOPE_INGEST) || $credential->organisation === null) {
            return $this->reject('credential_unusable', $keyId);
        }

        try {
            $secret = $this->issuer->secretFor($credential);
        } catch (Throwable) {
            return $this->reject('secret_undecryptable', $keyId); // e.g. APP_KEY rotated
        }

        if (! RequestSigner::verify($secret, $request->getMethod(), $request->getPathInfo(), $timestamp, $request->getContent(), $signature)) {
            return $this->reject('signature_mismatch', $keyId);
        }

        $this->tenant->set($credential->organisation);
        $request->attributes->set(self::CREDENTIAL_ATTRIBUTE, $credential);
        $this->touch($credential, $request);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }

    private function touch(ApiCredential $credential, Request $request): void
    {
        if ($credential->last_used_at === null || $credential->last_used_at->diffInSeconds(now()) > self::LAST_USED_RESOLUTION_SECONDS) {
            $credential->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
    }

    private function reject(string $reason, string $keyId): JsonResponse
    {
        // The reason is logged for operators, never returned to the caller.
        Log::warning('eye.ingest.auth_rejected', ['reason' => $reason, 'key_id' => substr($keyId, 0, 40)]);

        return ApiResponse::error('invalid_signature', 'Request authentication failed. Check the key, timestamp and signature.', 401);
    }
}
