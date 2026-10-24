<?php

namespace App\Domain\Eye;

use Carbon\CarbonImmutable;

/**
 * A validated, normalised event ready to be persisted.
 */
final readonly class EventData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $eventType,
        public string $source,
        public Severity $severity,
        public CarbonImmutable $occurredAt,
        public array $metadata,
        public ?string $assetName,
        public ?string $idempotencyKey,
    ) {}

    /**
     * Source IP promoted from metadata to an indexed column.
     */
    public function sourceIp(): ?string
    {
        $ip = $this->metadata['source_ip'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /**
     * Content fingerprint for deduplication: SHA-256 of a canonical JSON
     * encoding (keys sorted recursively, timestamp normalised to UTC with
     * microseconds) of everything that describes the event. The idempotency
     * key is deliberately excluded so the same payload is recognised whether
     * or not a key was supplied.
     */
    public function fingerprint(): string
    {
        $canonical = [
            'asset' => $this->assetName === null ? null : mb_strtolower($this->assetName),
            'event_type' => $this->eventType,
            'metadata' => self::sortRecursive($this->metadata),
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'severity' => $this->severity->value,
            'source' => $this->source,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }
}
