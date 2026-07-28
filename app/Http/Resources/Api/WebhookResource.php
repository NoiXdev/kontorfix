<?php

namespace App\Http\Resources\Api;

use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Webhook */
class WebhookResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'enabled' => $this->enabled,
            'has_secret' => (bool) $this->secret,
        ];
    }
}
