<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Organisation-bound machine credential for event ingestion (ADR-0006).
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $name
 * @property string $key_id
 * @property string $secret_encrypted
 * @property list<string> $scopes
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class ApiCredential extends Model
{
    use BelongsToOrganisation, HasUuids;

    public const SCOPE_INGEST = 'events:ingest';

    protected $fillable = ['organisation_id', 'name', 'key_id', 'secret_encrypted', 'scopes', 'expires_at', 'created_by'];

    /** The encrypted secret must never leave the model through serialisation. */
    protected $hidden = ['secret_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
