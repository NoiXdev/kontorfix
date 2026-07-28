<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWebhookRequest;
use App\Http\Resources\Api\WebhookResource;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WebhookResource::collection(Webhook::latest()->get());
    }

    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $data = $request->validated();

        $webhook = Webhook::create([
            'organization_id' => $request->user()->organization_id,
            'url' => $data['url'],
            'secret' => ($data['secret'] ?? null) ?: null,
            'events' => $data['events'],
        ]);

        // Frisch von der DB laden: Default-Werte (z. B. `enabled`) werden sonst
        // in der Resource nicht sichtbar, dieselbe Problematik wie bei UUID-Defaults.
        $webhook->refresh();

        return (new WebhookResource($webhook))->response()->setStatusCode(201);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(status: 204);
    }
}
