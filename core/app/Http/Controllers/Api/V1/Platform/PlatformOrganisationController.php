<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganisationResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform administration: organisation directory. Exposes organisation
 * metadata only — tenant security data still requires membership.
 */
class PlatformOrganisationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('platform.organisations.view');

        $list = ListQuery::fromRequest($request, ['name', 'created_at'], 'name');
        $page = $list->paginate(Organisation::query()->withCount('memberships'));

        return ApiResponse::paginated($page, OrganisationResource::class);
    }
}
