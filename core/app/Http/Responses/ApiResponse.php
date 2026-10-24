<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * The single place that defines KRATOS API envelopes:
 *
 *   success: {"data": …, "meta": {…}}
 *   error:   {"error": {"code", "message", "details"}, "request_id": "…"}
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => self::resolve($data)];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data, array $meta = []): JsonResponse
    {
        return self::ok($data, 201, $meta);
    }

    public static function accepted(string $message): JsonResponse
    {
        return new JsonResponse(['data' => ['message' => $message]], 202);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'data' => $resource::collection($paginator->items())->resolve(),
            'meta' => array_merge([
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ], $meta),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, string $message, int $status, ?array $details = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse([
            'error' => $error,
            'request_id' => request()->attributes->get('request_id'),
        ], $status, $headers);
    }

    private static function resolve(mixed $data): mixed
    {
        return $data instanceof JsonResource ? $data->resolve() : $data;
    }
}
