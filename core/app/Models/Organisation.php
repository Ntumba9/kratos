<?php

namespace App\Models;

use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed> $settings
 * @property int $event_retention_days
 */
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'settings', 'event_retention_days'];

    protected $attributes = [
        'settings' => '{}',
        'event_retention_days' => 90,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'event_retention_days' => 'integer',
        ];
    }

    /** @return HasMany<OrganisationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organisation_memberships')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Produce a URL-safe slug that is unique across organisations, including
     * soft-deleted ones (the unique index covers them too).
     */
    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'organisation' : Str::limit($base, 48, '');
        $base = trim($base, '-');
        $slug = $base;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
