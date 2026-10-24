<?php

namespace App\Http\Resources;

use App\Models\MonitoredAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MonitoredAsset */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'asset_type' => $this->asset_type,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'environment' => $this->environment,
            'criticality' => $this->criticality,
            'tags' => $this->tags ?? [],
            'description' => $this->description,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'events_last_24h' => $this->when(isset($this->events_last_24h), fn () => (int) $this->events_last_24h),
            'retired_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
