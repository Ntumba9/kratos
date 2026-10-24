<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Eye\CredentialIssuer;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCredentialResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\ApiCredential;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApiCredentialController extends Controller
{
    public function index(Request $request, Organisation $organisation): JsonResponse
    {
        $this->authorize('api_credentials.manage', $organisation);

        $list = ListQuery::fromRequest($request, ['created_at', 'name', 'last_used_at'], '-created_at');

        return ApiResponse::paginated($list->paginate(ApiCredential::query()->with('creator')), ApiCredentialResource::class);
    }

    public function store(Request $request, Organisation $organisation, CredentialIssuer $issuer): JsonResponse
    {
        $this->authorize('api_credentials.manage', $organisation);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $expiresAt = isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null;
        ['credential' => $credential, 'secret' => $secret] = $issuer->issue($organisation, $validated['name'], $expiresAt, $request->user());

        return ApiResponse::created([
            'credential' => (new ApiCredentialResource($credential->load('creator')))->resolve($request),
            // Shown exactly once; stored only encrypted (ADR-0006).
            'secret' => $secret,
        ]);
    }

    public function destroy(Organisation $organisation, string $credential, CredentialIssuer $issuer): Response
    {
        $this->authorize('api_credentials.manage', $organisation);

        // Tenant scope: a credential ID from another organisation is a 404.
        $issuer->revoke(ApiCredential::query()->findOrFail($credential));

        return ApiResponse::noContent();
    }
}
