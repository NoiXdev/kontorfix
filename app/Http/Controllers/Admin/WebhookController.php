<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWebhookRequest;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/webhooks/Index', [
            'webhooks' => Webhook::with(['deliveries' => fn ($q) => $q->latest('delivered_at')->limit(5)])
                ->latest()->get()
                ->map(fn (Webhook $w) => [
                    'id' => $w->id,
                    'url' => $w->url,
                    'events' => $w->events,
                    'enabled' => $w->enabled,
                    'has_secret' => (bool) $w->secret,
                    'recent_deliveries' => $w->deliveries->map(fn (WebhookDelivery $d) => [
                        'event' => $d->event,
                        'status_code' => $d->status_code,
                        'success' => $d->success,
                        'attempts' => $d->attempts,
                        'delivered_at' => $d->delivered_at?->diffForHumans(),
                    ])->values()->all(),
                ]),
            'incoming' => [
                'configured' => (bool) config('kontorfix.incoming_webhook_secret'),
                'urls' => [
                    'github' => url('/webhooks/github'),
                    'gitlab' => url('/webhooks/gitlab'),
                    'gitea' => url('/webhooks/gitea'),
                    'bitbucket' => url('/webhooks/bitbucket'),
                ],
            ],
        ]);
    }

    public function store(StoreWebhookRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Webhook::create([
            'organization_id' => $request->user()->organization_id,
            'url' => $data['url'],
            'secret' => $data['secret'] ?? null ?: null,
            'events' => $data['events'],
        ]);

        return back()->with('success', 'Webhook erstellt.');
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $webhook->delete();

        return back()->with('success', 'Webhook gelöscht.');
    }
}
