<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Http\Responses\ApiResponse;
use App\Models\Organisation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant isolation layer 1: resolves {organisation} from the route, verifies
 * the caller is a member, and binds the TenantContext for the rest of the
 * request. Non-members receive 404 so organisation IDs cannot be probed.
 */
class ResolveOrganisation
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeValue = $request->route('organisation');

        $organisationId = $routeValue instanceof Organisation ? $routeValue->getKey() : $routeValue;

        $membership = $user instanceof User && is_string($organisationId)
            ? $user->membershipIn($organisationId)
            : null;

        if ($membership === null) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        }

        $organisation = $membership->organisation;
        if ($organisation === null) { // soft-deleted
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        }

        $membership->setRelation('organisation', $organisation);
        $this->tenant->set($organisation, $membership);
        $request->route()?->setParameter('organisation', $organisation);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
