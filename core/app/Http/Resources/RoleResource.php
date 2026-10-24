<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'scope' => $this->scope,
            'description' => $this->description,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissionSlugs()),
        ];
    }
}
