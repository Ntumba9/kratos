<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Tenancy\OrganisationManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\CurrentUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    /**
     * Self-service sign-up: creates the user and their first organisation
     * (the user becomes its Organisation Administrator), sends a verification
     * email, and starts a browser session.
     */
    public function __invoke(RegisterRequest $request, OrganisationManager $organisations): JsonResponse
    {
        if (! $request->hasSession()) {
            return SessionGuardrail::statefulRequired();
        }

        $user = DB::transaction(function () use ($request, $organisations): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $organisations->create($user, $request->validated('organisation_name'));

            return $user;
        });

        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return ApiResponse::created(CurrentUserResource::for($user));
    }
}
