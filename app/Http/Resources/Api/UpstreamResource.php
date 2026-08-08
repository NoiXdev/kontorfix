<?php

namespace App\Http\Resources\Api;

use App\Models\Upstream;
use App\Support\CredentialUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Upstream */
class UpstreamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'type' => $this->type->value,
            // A Basic-auth mirror is configured as https://user:pass@host — the only form
            // UpstreamClient's Bearer-only auth leaves available — and this endpoint is
            // readable by any member-tier key, strictly below the admin who set it.
            'url' => CredentialUrl::redact($this->url),
            'policy' => $this->policy->value,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
            'has_auth' => (bool) $this->auth_token,
        ];
    }
}
