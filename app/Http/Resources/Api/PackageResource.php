<?php

namespace App\Http\Resources\Api;

use App\Models\Group;
use App\Models\Package;
use App\Support\CredentialUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Package */
class PackageResource extends JsonResource
{
    /**
     * How this package is assigned to ONE registry — absent unless a caller has said which
     * registry it is being rendered for, because outside that context the question has no
     * answer: the same package is assigned to several registries, on different dates.
     *
     * @var array{available_until: ?string, in_force: bool}|null
     */
    private ?array $assignment = null;

    /**
     * States the assignment fields for the registry this resource is being rendered under.
     *
     * `$inForce` is passed in rather than derived from `$availableUntil`, and that is the
     * point of the method rather than an inconvenience: the expiry predicate has exactly one
     * statement, {@see Group::assignedPackages()}, and a resource comparing the
     * date itself would be a second one — able to disagree with what the registry actually
     * serves, which is the defect these fields exist to report.
     */
    public function withAssignment(?string $availableUntil, bool $inForce): static
    {
        $this->assignment = ['available_until' => $availableUntil, 'in_force' => $inForce];

        return $this;
    }

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
        ] + ($this->assignment ?? []);
    }
}
