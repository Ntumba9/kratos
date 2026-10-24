<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessTokenResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal access tokens for programmatic API use. Tokens always expire,
 * carry explicit abilities, and are only ever displayed once.
 */
class AccessTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = $user->tokens()
            ->where('expires_at', '>', now())
            ->latest()
            ->get();

        return ApiResponse::ok(AccessTokenResource::collection($tokens));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::in(config('kratos.tokens.abilities'))],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:'.config('kratos.tokens.max_expiry_days')],
        ]);

        /** @var User $user */
        $user = $request->user();
        $days = (int) ($validated['expires_in_days'] ?? config('kratos.tokens.default_expiry_days'));

        $newToken = $user->createToken($validated['name'], $validated['abilities'], now()->addDays($days));

        $audit->record('auth.token.created', $newToken->accessToken, [
            'name' => $validated['name'],
            'abilities' => $validated['abilities'],
            'expires_in_days' => $days,
        ]);

        return ApiResponse::created([
            'token' => (new AccessTokenResource($newToken->accessToken))->resolve($request),
            // Shown exactly once; only its SHA-256 digest is stored.
            'plain_text_token' => $newToken->plainTextToken,
        ]);
    }

    public function destroy(Request $request, string $tokenId, AuditLogger $audit): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = ctype_digit($tokenId) ? $user->tokens()->whereKey((int) $tokenId)->first() : null;

        if ($token === null) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        }

        $token->delete();
        $audit->record('auth.token.revoked', $token, ['name' => $token->name]);

        return ApiResponse::noContent();
    }
}
