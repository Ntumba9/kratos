<?php

namespace App\Http;

use App\Domain\Eye\IdempotencyConflict;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps exceptions on API routes to the KRATOS error envelope. Internal
 * details (messages, traces) are only exposed when APP_DEBUG is enabled.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof IdempotencyConflict => ApiResponse::error(
                'idempotency_conflict',
                $e->getMessage(),
                409,
                ['existing_event_id' => $e->existingEventId],
            ),
            $e instanceof ValidationException => ApiResponse::error(
                'validation_failed',
                'The request payload is invalid.',
                422,
                $e->errors(),
            ),
            $e instanceof AuthenticationException => ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                401,
            ),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error(
                'forbidden',
                'You do not have permission to perform this action.',
                403,
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(
                'not_found',
                'The requested resource was not found.',
                404,
            ),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'method_not_allowed',
                'The HTTP method is not allowed for this resource.',
                405,
                headers: $e->getHeaders(),
            ),
            $e instanceof TokenMismatchException => ApiResponse::error(
                'csrf_token_mismatch',
                'The CSRF token is missing or invalid. Refresh and try again.',
                419,
            ),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'rate_limited',
                'Too many requests. Retry after the period indicated by the Retry-After header.',
                429,
                headers: $e->getHeaders(),
            ),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $this->codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.',
                $e->getStatusCode(),
                headers: $e->getHeaders(),
            ),
            default => ApiResponse::error(
                'internal_error',
                'An unexpected error occurred.',
                500,
                config('app.debug') ? ['exception' => $e::class, 'message' => $e->getMessage()] : null,
            ),
        };
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            503 => 'service_unavailable',
            default => 'http_error',
        };
    }
}
