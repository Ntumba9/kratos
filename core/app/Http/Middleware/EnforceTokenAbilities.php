<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal access tokens carry coarse abilities: `read` for safe methods and
 * `write` for anything that changes state. Session (cookie) requests are not
 * restricted here; their permissions come from the user's role alone.
 */
class EnforceTokenAbilities
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $required = $request->isMethodSafe() ? 'read' : 'write';

            if (! $token->can($required)) {
                return ApiResponse::error(
                    'token_ability_missing',
                    "This access token does not have the '{$required}' ability.",
                    403,
                );
            }
        }

        return $next($request);
    }
}
