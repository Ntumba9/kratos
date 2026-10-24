<?php

namespace App\Domain\Eye;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Strict schema validation and normalisation for a single inbound event.
 *
 * Example accepted payload:
 *   {
 *     "event_type": "authentication_failure",
 *     "source": "application_server",
 *     "severity": "medium",
 *     "timestamp": "2026-10-23T12:00:00Z",
 *     "asset": "billing-api",               // optional, must exist
 *     "idempotency_key": "evt-123",          // optional (or Idempotency-Key header)
 *     "metadata": { "failed_attempts": 12, "source_ip": "192.0.2.10" }
 *   }
 */
final class EventValidator
{
    private const ALLOWED_FIELDS = ['event_type', 'source', 'severity', 'timestamp', 'metadata', 'asset', 'idempotency_key'];

    /**
     * @param  mixed  $payload  decoded JSON
     * @param  string  $prefix  error key prefix (e.g. "events.3.") for batches
     *
     * @throws ValidationException
     */
    public function validate(mixed $payload, string $prefix = ''): EventData
    {
        if (! is_array($payload) || array_is_list($payload) && $payload !== []) {
            throw ValidationException::withMessages([rtrim($prefix, '.') ?: 'payload' => ['The event must be a JSON object.']]);
        }

        $errors = [];

        $unknown = array_diff(array_keys($payload), self::ALLOWED_FIELDS);
        foreach ($unknown as $field) {
            $errors[$prefix.$field][] = 'Unknown field. Allowed fields: '.implode(', ', self::ALLOWED_FIELDS).'.';
        }

        $validator = Validator::make($payload, [
            'event_type' => ['required', 'string', 'max:64', 'regex:/^(custom\.)?[a-z][a-z0-9_]{2,63}$/'],
            'source' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'severity' => ['required', 'string', 'in:'.implode(',', Severity::values())],
            'timestamp' => ['required', 'string', 'max:40'],
            'metadata' => ['sometimes', 'array'],
            'asset' => ['sometimes', 'nullable', 'string', 'max:120'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$prefix.$field] = array_merge($errors[$prefix.$field] ?? [], $messages);
        }

        $eventType = is_string($payload['event_type'] ?? null) ? $payload['event_type'] : '';
        if ($eventType !== '' && ! isset($errors[$prefix.'event_type'])
            && ! str_starts_with($eventType, 'custom.')
            && ! array_key_exists($eventType, config('kratos.event_types'))) {
            $errors[$prefix.'event_type'][] = 'Unknown event type. Use a catalogued type or the "custom." prefix.';
        }

        $occurredAt = null;
        if (is_string($payload['timestamp'] ?? null) && ! isset($errors[$prefix.'timestamp'])) {
            $occurredAt = $this->parseTimestamp($payload['timestamp'], $prefix, $errors);
        }

        $metadata = $payload['metadata'] ?? [];
        if (is_array($metadata) && ! isset($errors[$prefix.'metadata'])) {
            if ($metadata !== [] && array_is_list($metadata)) {
                $errors[$prefix.'metadata'][] = 'Metadata must be a JSON object.';
            } else {
                $this->validateMetadata($metadata, $eventType, $prefix, $errors);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new EventData(
            eventType: $eventType,
            source: $payload['source'],
            severity: Severity::from($payload['severity']),
            occurredAt: $occurredAt,
            metadata: $metadata,
            assetName: isset($payload['asset']) && $payload['asset'] !== '' ? trim($payload['asset']) : null,
            idempotencyKey: $payload['idempotency_key'] ?? null,
        );
    }

    /**
     * RFC 3339 with an explicit offset; not further in the future than the
     * allowed clock skew and not older than the longest permitted retention.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function parseTimestamp(string $value, string $prefix, array &$errors): ?CarbonImmutable
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

        if (preg_match($pattern, $value) !== 1) {
            $errors[$prefix.'timestamp'][] = 'The timestamp must be RFC 3339 with a timezone, e.g. 2026-10-23T12:00:00Z.';

            return null;
        }

        try {
            $time = CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            $errors[$prefix.'timestamp'][] = 'The timestamp is not a valid date.';

            return null;
        }

        if ($time->isAfter(now()->addSeconds(config('kratos.ingestion.max_future_skew_seconds')))) {
            $errors[$prefix.'timestamp'][] = 'The timestamp is in the future.';
        } elseif ($time->isBefore(now()->subDays(3650))) {
            $errors[$prefix.'timestamp'][] = 'The timestamp is too far in the past.';
        }

        return $time;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, list<string>>  $errors
     */
    private function validateMetadata(array $metadata, string $eventType, string $prefix, array &$errors): void
    {
        $limits = config('kratos.ingestion.metadata');
        $key = $prefix.'metadata';

        if (count($metadata) > $limits['max_keys']) {
            $errors[$key][] = "Metadata may contain at most {$limits['max_keys']} top-level keys.";
        }

        if (strlen((string) json_encode($metadata)) > $limits['max_bytes']) {
            $errors[$key][] = "Metadata may not exceed {$limits['max_bytes']} bytes when encoded.";
        }

        $this->walk($metadata, 1, $limits, $key, $errors);

        foreach (config("kratos.event_types.{$eventType}", []) as $field => $type) {
            if (! array_key_exists($field, $metadata) || $metadata[$field] === null) {
                continue;
            }

            if (! $this->matchesType($metadata[$field], $type)) {
                $errors["{$key}.{$field}"][] = "Must be of type {$type} for event type {$eventType}.";
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, int>  $limits
     * @param  array<string, list<string>>  $errors
     */
    private function walk(array $value, int $depth, array $limits, string $key, array &$errors): void
    {
        if ($depth > $limits['max_depth']) {
            $errors[$key][] = "Metadata may be nested at most {$limits['max_depth']} levels deep.";

            return;
        }

        foreach ($value as $name => $item) {
            if (is_string($name) && (strlen($name) > 64 || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1)) {
                $errors[$key][] = 'Metadata keys must be 1-64 characters of letters, digits, "_", "." or "-".';

                return;
            }
            if (is_string($item) && mb_strlen($item) > $limits['max_string_length']) {
                $errors["{$key}.{$name}"][] = "Strings may not exceed {$limits['max_string_length']} characters.";
            }
            if (is_array($item)) {
                $this->walk($item, $depth + 1, $limits, $key, $errors);
            }
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'ip' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'integer' => is_int($value) && $value >= 0,
            'boolean' => is_bool($value),
            'username' => is_string($value) && $value !== '' && mb_strlen($value) <= 256,
            'string' => is_string($value),
            default => false,
        };
    }
}
