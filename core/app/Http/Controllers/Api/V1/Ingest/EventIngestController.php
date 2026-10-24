<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Domain\Eye\EventIngestor;
use App\Domain\Eye\EventValidator;
use App\Domain\Eye\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateIngestionClient;
use App\Http\Responses\ApiResponse;
use App\Models\ApiCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * KRATOS EYE ingestion endpoints (machine clients, HMAC-signed).
 *
 *   POST /api/v1/ingest/events         one event       → 202 accepted | 200 duplicate
 *   POST /api/v1/ingest/events/batch   ≤ 100 events    → per-event results
 */
class EventIngestController extends Controller
{
    public function __construct(
        private readonly EventValidator $validator,
        private readonly EventIngestor $ingestor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (($error = $this->rejectNonJson($request)) !== null) {
            return $error;
        }

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $headerKey = $request->headers->get('Idempotency-Key');
        if ($headerKey !== null) {
            if (isset($payload['idempotency_key']) && $payload['idempotency_key'] !== $headerKey) {
                throw ValidationException::withMessages(['idempotency_key' => ['Conflicts with the Idempotency-Key header.']]);
            }
            if (is_array($payload)) {
                $payload['idempotency_key'] = $headerKey;
            }
        }

        $data = $this->validator->validate($payload);
        $result = $this->ingestor->ingest($data, $this->credential($request));

        return ApiResponse::ok([
            'id' => $result->event->id,
            'status' => $result->duplicate ? 'duplicate' : 'accepted',
            'duplicate' => $result->duplicate,
            'processing_status' => $result->event->processing_status->value,
            'received_at' => $result->event->received_at->toIso8601String(),
        ], $result->duplicate ? 200 : 202);
    }

    /**
     * Each event is validated and stored independently, so one bad event
     * does not reject the batch. Status: 202 if anything was accepted,
     * 200 if everything was a duplicate, 422 if everything was rejected.
     */
    public function batch(Request $request): JsonResponse
    {
        if (($error = $this->rejectNonJson($request)) !== null) {
            return $error;
        }

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $max = config('kratos.ingestion.max_batch_size');
        $events = $payload['events'] ?? null;

        if (! is_array($events) || ! array_is_list($events) || $events === [] || count($events) > $max || array_diff(array_keys($payload), ['events']) !== []) {
            throw ValidationException::withMessages(['events' => ["Provide only an \"events\" array of 1 to {$max} events."]]);
        }

        $credential = $this->credential($request);
        $results = [];
        $counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];

        foreach ($events as $index => $event) {
            $prefix = "events.{$index}.";

            try {
                $result = $this->ingestor->ingest($this->validator->validate($event, $prefix), $credential, $prefix);
                $status = $result->duplicate ? 'duplicate' : 'accepted';
                $results[] = ['index' => $index, 'status' => $status, 'id' => $result->event->id];
            } catch (ValidationException $e) {
                $status = 'rejected';
                $results[] = ['index' => $index, 'status' => $status, 'errors' => $e->errors()];
            } catch (IdempotencyConflict $e) {
                $status = 'rejected';
                $results[] = ['index' => $index, 'status' => $status, 'errors' => [$prefix.'idempotency_key' => [$e->getMessage()]], 'existing_event_id' => $e->existingEventId];
            }

            $counts[$status]++;
        }

        $httpStatus = match (true) {
            $counts['accepted'] > 0 => 202,
            $counts['rejected'] === count($events) => 422,
            default => 200,
        };

        return ApiResponse::ok($results, $httpStatus, ['summary' => $counts]);
    }

    private function credential(Request $request): ApiCredential
    {
        return $request->attributes->get(AuthenticateIngestionClient::CREDENTIAL_ATTRIBUTE);
    }

    private function rejectNonJson(Request $request): ?JsonResponse
    {
        if (! $request->isJson()) {
            return ApiResponse::error('unsupported_media_type', 'Send the body as application/json.', 415);
        }

        return null;
    }

    private function decode(Request $request): mixed
    {
        try {
            return json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ApiResponse::error('invalid_json', 'The request body is not valid JSON.', 400);
        }
    }
}
