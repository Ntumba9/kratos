<?php

namespace App\Models;

use Database\Factories\OrganisationMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bridge between users and tenants. It is deliberately *not* tenant
 * scoped: it is the table used to resolve the tenant in the first place.
 * Access is always through an Organisation or User relation.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $user_id
 * @property int $role_id
 * @property string|null $invited_by
 */
class OrganisationMembership extends Model
{
    /** @use HasFactory<OrganisationMembershipFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['organisation_id', 'user_id', 'role_id', 'invited_by'];

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function grants(string $permission): bool
    {
        return $this->role->grants($permission);
    }
}
