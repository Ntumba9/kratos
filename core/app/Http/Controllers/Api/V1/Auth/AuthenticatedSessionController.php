<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return SessionGuardrail::statefulRequired();
        }

        $credentials = [
            'email' => mb_strtolower($request->validated('email')),
            'password' => $request->validated('password'),
        ];

        // Failed attempts are audited by AuthEventSubscriber (Failed event).
        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            return ApiResponse::error('invalid_credentials', 'These credentials do not match our records.', 401);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::ok(CurrentUserResource::for($user));
    }

    public function destroy(Request $request): Response|JsonResponse
    {
        if (! $request->hasSession()) {
            return SessionGuardrail::statefulRequired();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::noContent();
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(CurrentUserResource::for($request->user()));
    }
}
