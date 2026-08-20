<?php

namespace App\Http\Resources\Api;

use App\Models\Package;
use App\Support\CredentialUrl;
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
            // A git PAT is commonly written as userinfo instead of into the dedicated,
            // encrypted `repository_token`. This endpoint is member-tier (scopePackageRead
            // is membership, not administration), so the credential is withheld here.
            'repository_url' => CredentialUrl::redact($this->repository_url),
            'sync_status' => $this->sync_status->value,
            'sync_error' => $this->sync_error,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'abandoned_at' => $this->abandoned_at?->toIso8601String(),
            'replacement_package' => $this->replacement_package,
            'abandonment_reason' => $this->abandonment_reason,
            'versions' => PackageVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
