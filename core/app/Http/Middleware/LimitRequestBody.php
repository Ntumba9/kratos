<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects oversized bodies before any parsing or signature work. Checks the
 * declared Content-Length first (cheap), then the actual body length (a
 * client can omit or misstate the header with chunked encoding).
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, ?string $maxBytes = null): Response
    {
        $limit = (int) ($maxBytes ?? config('kratos.ingestion.max_body_bytes'));

        $declared = $request->headers->get('Content-Length');
        if (($declared !== null && (int) $declared > $limit) || strlen($request->getContent()) > $limit) {
            return ApiResponse::error('payload_too_large', "The request body may not exceed {$limit} bytes.", 413);
        }

        return $next($request);
    }
}
