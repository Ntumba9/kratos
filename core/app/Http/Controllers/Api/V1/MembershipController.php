<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\OrganisationManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ListQuery;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MembershipController extends Controller
{
    public function index(Request $request, Organisation $organisation): JsonResponse
    {
        $this->authorize('members.view', $organisation);

        $list = ListQuery::fromRequest($request, ['created_at'], 'created_at');

        $page = $list->paginate($organisation->memberships()->with(['user', 'role'])->getQuery());

        return ApiResponse::paginated($page, MembershipResource::class);
    }

    /**
     * Add an existing, verified KRATOS user to the organisation.
     */
    public function store(Request $request, Organisation $organisation, OrganisationManager $manager): JsonResponse
    {
        $this->authorize('members.manage', $organisation);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')->where('scope', Role::SCOPE_ORGANISATION)],
        ]);

        $user = User::query()->where('email', mb_strtolower($validated['email']))->first();

        if ($user === null || ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['No verified KRATOS account exists for this email address.'],
            ]);
        }

        $membership = $manager->addMember($organisation, $user, Role::findBySlug($validated['role']), $request->user());

        return ApiResponse::created(new MembershipResource($membership));
    }

    public function update(Request $request, Organisation $organisation, OrganisationMembership $membership, OrganisationManager $manager): JsonResponse
    {
        $this->authorize('update', $membership);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'slug')->where('scope', Role::SCOPE_ORGANISATION)],
        ]);

        $membership = $manager->changeRole($membership, Role::findBySlug($validated['role']));

        return ApiResponse::ok(new MembershipResource($membership));
    }

    public function destroy(Organisation $organisation, OrganisationMembership $membership, OrganisationManager $manager): Response
    {
        $this->authorize('delete', $membership);

        $manager->removeMember($membership);

        return ApiResponse::noContent();
    }
}
