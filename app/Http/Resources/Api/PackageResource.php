<?php

namespace App\Http\Resources\Api;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Package */
class PackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'description' => $this->description,
            'repository_url' => $this->repository_url,
            'sync_status' => $this->sync_status->value,
            'sync_error' => $this->sync_error,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'versions' => PackageVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
