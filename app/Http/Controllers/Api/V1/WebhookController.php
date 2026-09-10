<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWebhookRequest;
use App\Http\Resources\Api\WebhookResource;
use App\Models\Webhook;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Webhooks')]
class WebhookController extends Controller
{
    use ScopesApiToUser;

    /**
     * Webhooks auflisten (nur Super-Admin, siehe Middleware). Ein Super-Admin sieht alle
     * Organisationen inklusive der legacy Einträge ohne Organisation; die Filterung nach
     * `administeredOrganizationIds()` ist hier defensiv — die Route erlaubt heute ohnehin
     * nur Super-Admins.
     */
    public function index(): AnonymousResourceCollection
    {
        $query = Webhook::latest();
        if (! $this->seesAllOrganizations()) {
            $query->whereIn('organization_id', $this->apiUser()->administeredOrganizationIds());
        }

        return WebhookResource::collection($query->get());
    }

    /** Neuen Webhook anlegen. */
    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $data = $request->validated();

        $organizationId = $this->resolveWriteOrg($data['organization_id'] ?? null);

        $webhook = Webhook::create([
            'organization_id' => $organizationId,
            'url' => $data['url'],
            'secret' => ($data['secret'] ?? null) ?: null,
            'events' => $data['events'],
        ]);

        // Reload fresh from the DB: otherwise default values (e.g. `enabled`)
        // wouldn't be visible in the resource, the same issue as with UUID defaults.
        $webhook->refresh();

        return (new WebhookResource($webhook))->response()->setStatusCode(201);
    }

    /** Webhook löschen. */
    public function destroy(Webhook $webhook): JsonResponse
    {
        if ($webhook->organization_id !== null) {
            $this->assertCanWriteOrg($webhook->organization_id);
        } else {
            // A null-org (legacy) webhook is instance-wide config, touchable only unscoped.
            abort_unless($this->seesAllOrganizations(), 403);
        }

        $webhook->delete();

        return response()->json(status: 204);
    }
}
