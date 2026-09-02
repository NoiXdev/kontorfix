<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreIncomingWebhookRequest;
use App\Http\Requests\Admin\StoreWebhookRequest;
use App\Models\IncomingWebhook;
use App\Models\IncomingWebhookEvent;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
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
            'incoming' => IncomingWebhook::latest()->get()->map(fn (IncomingWebhook $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'provider' => $w->provider,
                'enabled' => $w->enabled,
                'url' => url("/webhooks/{$w->provider}/{$w->id}"),
                'last_received_at' => $w->last_received_at?->diffForHumans(),
                // Raw ISO timestamp for sorting only — `last_received_at` above is a relative
                // string ("vor 3 Tagen") that Date.parse cannot read, so the display value
                // and the sort value have to travel separately.
                'last_received_at_iso' => $w->last_received_at?->toIso8601String(),
            ]),
            // Legacy shared endpoints (env secret) — still shown for reference.
            'legacy' => [
                'configured' => (bool) config('kontorfix.incoming_webhook_secret'),
                'urls' => [
                    'github' => url('/webhooks/github'),
                    'gitlab' => url('/webhooks/gitlab'),
                    'gitea' => url('/webhooks/gitea'),
                    'bitbucket' => url('/webhooks/bitbucket'),
                ],
            ],
            // Audit: the most recent incoming and outgoing traffic with payloads.
            'audit' => [
                'incoming' => IncomingWebhookEvent::with('incomingWebhook:id,name')
                    ->latest()->limit(50)->get()
                    ->map(fn (IncomingWebhookEvent $e) => [
                        'id' => $e->id,
                        'source' => $e->incomingWebhook?->name,
                        'provider' => $e->provider,
                        'repo_url' => $e->repo_url,
                        'signature_valid' => $e->signature_valid,
                        'matched_packages' => $e->matched_packages,
                        'status_code' => $e->status_code,
                        'ip' => $e->ip,
                        'payload' => $e->payload,
                        'received_at' => $e->created_at?->diffForHumans(),
                    ]),
                'outgoing' => WebhookDelivery::with('webhook:id,url')
                    ->latest()->limit(50)->get()
                    ->map(fn (WebhookDelivery $d) => [
                        'id' => $d->id,
                        'url' => $d->webhook?->url,
                        'event' => $d->event,
                        'status_code' => $d->status_code,
                        'success' => $d->success,
                        'attempts' => $d->attempts,
                        'error' => $d->error,
                        'payload' => $d->payload,
                        'delivered_at' => $d->delivered_at?->diffForHumans(),
                    ]),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/webhooks/Create');
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

        // Explicitly to the index, not back(): the form now lives on its own
        // `admin/webhooks/create` page, and back() would return there — to a freshly emptied
        // form that renders no `flash.success`. Same reason as storeIncoming() below.
        return redirect()->route('admin.webhooks.index')->with('success', 'Webhook erstellt.');
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $webhook->delete();

        return back()->with('success', 'Webhook gelöscht.');
    }

    public function createIncoming(): Response
    {
        return Inertia::render('admin/webhooks/IncomingCreate');
    }

    public function storeIncoming(StoreIncomingWebhookRequest $request): RedirectResponse
    {
        // Generate the secret server-side and reveal it once — it has to be copied into
        // the git host's webhook configuration. It is stored encrypted thereafter.
        $secret = 'whsec_'.Str::random(40);

        $hook = IncomingWebhook::create([
            'name' => $request->validated('name'),
            'provider' => $request->validated('provider'),
            'secret' => $secret,
        ]);

        // Explicitly to the index, not back(): the mint now happens from its own
        // `admin/incoming-webhooks/create` page, and `back()` would return there — where
        // the one-time plaintext reveal has nowhere to render. The index is the only page
        // that shows it.
        return redirect()->route('admin.webhooks.index')
            ->with('success', "Eingehender Webhook {$hook->name} erstellt.")
            ->with('incomingWebhookSecret', $secret)
            ->with('incomingWebhookUrl', url("/webhooks/{$hook->provider}/{$hook->id}"));
    }

    public function regenerateIncoming(IncomingWebhook $incoming): RedirectResponse
    {
        $secret = 'whsec_'.Str::random(40);
        $incoming->update(['secret' => $secret]);

        return back()
            ->with('success', 'Secret neu erzeugt.')
            ->with('incomingWebhookSecret', $secret)
            ->with('incomingWebhookUrl', url("/webhooks/{$incoming->provider}/{$incoming->id}"));
    }

    public function destroyIncoming(IncomingWebhook $incoming): RedirectResponse
    {
        $incoming->delete();

        return back()->with('success', 'Eingehender Webhook gelöscht.');
    }
}
