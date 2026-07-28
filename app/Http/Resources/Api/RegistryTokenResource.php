<?php

namespace App\Http\Resources\Api;

use App\Models\RegistryToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RegistryToken */
class RegistryTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ability' => $this->ability->value,
            'organization_id' => $this->organization_id,
            'group_id' => $this->group_id,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'plain_text' => $this->when(isset($this->plain_text), fn () => $this->plain_text),
        ];
    }
}
