<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw new MissingTenantContext(sprintf(
                'Query on tenant-owned model [%s] attempted without a tenant context. '
                .'Set TenantContext, or call withoutTenancy() for an intentional cross-tenant query.',
                $model::class,
            ));
        }

        $builder->where($model->qualifyColumn('organisation_id'), $context->organisationId());
    }
}
