<?php

namespace App\Http\Resources\Api;

use App\Models\Upstream;
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
            'url' => $this->url,
            'policy' => $this->policy->value,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
            'has_auth' => (bool) $this->auth_token,
        ];
    }
}
