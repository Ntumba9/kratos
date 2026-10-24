<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Browser-session endpoints (register, login, logout) only work for
 * first-party SPA requests, which Sanctum identifies by Origin/Referer and
 * equips with a session. Programmatic clients use personal access tokens.
 */
final class SessionGuardrail
{
    public static function statefulRequired(): JsonResponse
    {
        return ApiResponse::error(
            'stateful_request_required',
            'This endpoint is for the KRATOS web application. Programmatic clients should authenticate with a personal access token.',
            400,
        );
    }
}
