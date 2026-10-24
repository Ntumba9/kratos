<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Built-in rule (organisation_id NULL) or an organisation's override of it.
 *
 * Not tenant scoped through BelongsToOrganisation because built-in rows are
 * shared; every read goes through DetectionRuleCatalog, which only ever
 * combines the built-ins with the *current* organisation's overrides.
 *
 * @property string $id
 * @property string|null $organisation_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $category
 * @property string $severity
 * @property bool $enabled
 * @property array<string, float|int> $parameters
 */
class DetectionRule extends Model
{
    use HasUuids;

    protected $fillable = ['organisation_id', 'key', 'name', 'description', 'category', 'severity', 'enabled', 'parameters', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'parameters' => 'array'];
    }

    /**
     * @param  Builder<DetectionRule>  $query
     * @return Builder<DetectionRule>
     */
    public function scopeBuiltIn(Builder $query): Builder
    {
        return $query->whereNull('organisation_id');
    }
}
