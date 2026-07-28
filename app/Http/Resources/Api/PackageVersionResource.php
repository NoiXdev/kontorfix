<?php

namespace App\Http\Resources\Api;

use App\Models\PackageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PackageVersion */
class PackageVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version_pretty ?? $this->version,
            'released_at' => $this->released_at?->toIso8601String(),
            'reference' => $this->source_reference,
        ];
    }
}
