<?php

namespace App\Http\Resources;

use App\Models\DetectionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DetectionResult */
class DetectionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'detector' => $this->detector,
            'rule_key' => $this->rule_key,
            'severity' => $this->severity,
            'category' => $this->category,
            'score' => (float) $this->score,
            'explanation' => $this->explanation,
            'details' => (object) ($this->details ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'event' => $this->whenLoaded('event', fn () => $this->event ? [
                'id' => $this->event->id,
                'event_type' => $this->event->event_type,
                'severity' => $this->event->severity->value,
                'source_ip' => $this->event->source_ip,
                'occurred_at' => $this->event->occurred_at->toIso8601String(),
                'risk_score' => $this->event->risk_score === null ? null : (float) $this->event->risk_score,
                'verdict' => $this->event->verdict,
            ] : null),
        ];
    }
}
