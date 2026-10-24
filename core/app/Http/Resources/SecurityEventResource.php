<?php

namespace App\Http\Resources;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SecurityEvent */
class SecurityEventResource extends JsonResource
{
    /** Include pipeline internals (outbox state, errors) — detail view only. */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'source' => $this->source,
            'severity' => $this->severity->value,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            'source_ip' => $this->source_ip,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset ? [
                'id' => $this->asset->id,
                'name' => $this->asset->name,
                'criticality' => $this->asset->criticality,
            ] : null),
            'processing_status' => $this->processing_status->value,
            'risk_score' => $this->risk_score === null ? null : (float) $this->risk_score,
            'verdict' => $this->verdict,
            'threat_category' => $this->threat_category,
            'metadata' => (object) ($this->metadata ?? []),
        ];

        if ($this->detailed) {
            $data += [
                'idempotency_key' => $this->idempotency_key,
                'fingerprint' => $this->fingerprint,
                'credential' => $this->whenLoaded('credential', fn () => $this->credential ? [
                    'id' => $this->credential->id,
                    'name' => $this->credential->name,
                    'key_id' => $this->credential->key_id,
                ] : null),
                'analysis' => [
                    'analysed_at' => $this->analysed_at?->toIso8601String(),
                    'summary' => $this->analysis_summary,
                    'anomaly_percentile' => $this->anomaly_percentile === null ? null : (float) $this->anomaly_percentile,
                    'model_version' => $this->whenLoaded('modelVersion', fn () => $this->modelVersion?->version),
                    'detections' => $this->whenLoaded('detections', fn () => DetectionResultResource::collection($this->detections)->resolve()),
                ],
                'processing' => [
                    'attempts' => $this->processing_attempts,
                    'last_error' => $this->last_error,
                    'started_at' => $this->processing_started_at?->toIso8601String(),
                    'processed_at' => $this->processed_at?->toIso8601String(),
                    'published_at' => $this->whenLoaded('outbox', fn () => $this->outbox?->published_at?->toIso8601String()),
                    'publish_attempts' => $this->whenLoaded('outbox', fn () => $this->outbox?->publish_attempts),
                ],
            ];
        }

        return $data;
    }
}
