<?php

namespace App\Domain\Tenancy;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * - Every query is constrained to the current TenantContext; querying with no
 *   context throws MissingTenantContext (fail closed).
 * - New records inherit organisation_id from the context unless the model
 *   opts out with `protected bool $fillsOrganisationFromContext = false`.
 * - `Model::withoutTenancy()` is the single, greppable escape hatch for
 *   deliberate cross-tenant access (platform administration, retention jobs).
 *
 * @mixin Model
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $fills = property_exists($model, 'fillsOrganisationFromContext')
                ? $model->fillsOrganisationFromContext
                : true;

            if ($fills && $model->getAttribute('organisation_id') === null) {
                $model->setAttribute('organisation_id', app(TenantContext::class)->organisationId());
            }
        });
    }

    /**
     * @return Builder<static>
     */
    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
