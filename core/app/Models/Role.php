<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $scope
 * @property string|null $description
 */
class Role extends Model
{
    public const PLATFORM_ADMIN = 'platform_admin';

    public const ORG_ADMIN = 'org_admin';

    public const ANALYST = 'analyst';

    public const VIEWER = 'viewer';

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_ORGANISATION = 'organisation';

    protected $fillable = ['slug', 'name', 'scope', 'description'];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeOrganisationScoped(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_ORGANISATION);
    }

    /**
     * @return list<string>
     */
    public function permissionSlugs(): array
    {
        return $this->permissions->pluck('slug')->sort()->values()->all();
    }

    public function grants(string $permission): bool
    {
        return $this->permissions->contains('slug', $permission);
    }

    public static function findBySlug(string $slug): self
    {
        return static::query()->where('slug', $slug)->firstOrFail();
    }
}
