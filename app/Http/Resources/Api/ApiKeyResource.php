<?php

namespace App\Http\Resources\Api;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApiKey */
class ApiKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permission' => $this->permission->value,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Plain text ONLY right after creation (set via a dynamic attribute).
            'plain_text' => $this->when(isset($this->plain_text), fn () => $this->plain_text),
        ];
    }
}
