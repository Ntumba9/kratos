<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\OrganisationManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganisationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganisationController extends Controller
{
    /**
     * Organisations the caller belongs to.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organisations = $user->organisations()->orderBy('name')->get();

        return ApiResponse::ok(OrganisationResource::collection($organisations));
    }

    public function store(Request $request, OrganisationManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $organisation = $manager->create($request->user(), $validated['name']);

        return ApiResponse::created(new OrganisationResource($organisation));
    }

    public function show(Organisation $organisation): JsonResponse
    {
        $this->authorize('organisation.view', $organisation);

        return ApiResponse::ok(new OrganisationResource($organisation));
    }

    public function update(Request $request, Organisation $organisation, OrganisationManager $manager): JsonResponse
    {
        $this->authorize('organisation.update', $organisation);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'event_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);

        return ApiResponse::ok(new OrganisationResource($manager->update($organisation, $validated)));
    }
}
