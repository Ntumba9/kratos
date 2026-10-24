<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Writes append-only audit records. Callers describe *what* happened; the
 * logger captures *who*, *where from* and *which request* automatically.
 */
final class AuditLogger
{
    /** Metadata keys that must never be persisted, at any depth. */
    private const REDACTED_KEYS = ['password', 'password_confirmation', 'current_password', 'token', 'secret', 'plain_text_token'];

    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $target = null,
        array $metadata = [],
        ?Organisation $organisation = null,
        ?User $actor = null,
    ): AuditLog {
        $organisation ??= $this->tenant->has() ? $this->tenant->organisation() : null;
        $actor ??= $this->resolveActor();

        return AuditLog::create([
            'organisation_id' => $organisation?->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $target ? Str::snake(class_basename($target)) : null,
            'target_id' => $target ? (string) $target->getKey() : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 509),
            'request_id' => $this->request->attributes->get('request_id'),
            'metadata' => $this->redact($metadata),
        ]);
    }

    private function resolveActor(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redact(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (in_array(Str::lower((string) $key), self::REDACTED_KEYS, true)) {
                $metadata[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $metadata[$key] = Arr::isList($value) ? $value : $this->redact($value);
            }
        }

        return $metadata;
    }
}
