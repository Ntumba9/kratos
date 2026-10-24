<?php

namespace App\Http\Resources;

use App\Models\OrganisationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganisationMembership */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'role' => $this->whenLoaded('role', fn () => [
                'slug' => $this->role->slug,
                'name' => $this->role->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
