<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record. Writes go through App\Domain\Audit\AuditLogger.
 * Reads are tenant scoped; platform/authentication records (organisation_id
 * NULL) are only reachable with an explicit withoutTenancy() query.
 *
 * @property string $id
 * @property string|null $organisation_id
 * @property string|null $actor_user_id
 * @property string $action
 * @property array<string, mixed> $metadata
 */
class AuditLog extends Model
{
    use BelongsToOrganisation, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organisation_id', 'actor_user_id', 'action', 'target_type', 'target_id',
        'ip_address', 'user_agent', 'request_id', 'metadata',
    ];

    /**
     * Audit records are written without a tenant context for platform-level
     * events, so organisation_id is never auto-filled from the context.
     */
    protected bool $fillsOrganisationFromContext = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
