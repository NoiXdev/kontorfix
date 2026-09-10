<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreIncomingWebhookRequest;
use App\Http\Requests\Admin\StoreWebhookRequest;
use App\Models\IncomingWebhook;
use App\Models\IncomingWebhookEvent;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Scope\OrgScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        // Outgoing webhooks carry an organization (nullable — legacy, instance-wide config
        // predates the column). A super-admin viewing "all orgs" sees everything, including
        // those legacy rows; anyone scoped to specific organizations only sees rows owned by
        // one of them, so a null-org legacy row never leaks into a customer-org admin's view.
        $spansAll = app(OrgScope::class)->spansAllOrganizations();
        $orgIds = $this->scopedOrgIds();

        $webhooksQuery = Webhook::with(['organization:id,name', 'deliveries' => fn ($q) => $q->latest('delivered_at')->limit(5)])
            ->latest();
        if (! $spansAll) {
            $webhooksQuery->whereIn('organization_id', $orgIds);
        }

        $outgoingAuditQuery = WebhookDelivery::with('webhook:id,url,organization_id')->latest()->limit(50);
        if (! $spansAll) {
            $outgoingAuditQuery->whereHas('webhook', fn ($q) => $q->whereIn('organization_id', $orgIds));
        }

        return Inertia::render('admin/webhooks/Index', [
            'webhooks' => $webhooksQuery->get()
                ->map(fn (Webhook $w) => [
                    'id' => $w->id,
                    'url' => $w->url,
                    'events' => $w->events,
                    'enabled' => $w->enabled,
                    'has_secret' => (bool) $w->secret,
                    'organization' => $w->organization?->name,
                    'recent_deliveries' => $w->deliveries->map(fn (WebhookDelivery $d) => [
                        'event' => $d->event,
                        'status_code' => $d->status_code,
                        'success' => $d->success,
                        'attempts' => $d->attempts,
                        'delivered_at' => $d->delivered_at?->diffForHumans(),
                    ])->values()->all(),
                ]),
            // Incoming webhook endpoints have no organization dimension at all (no
            // `organization_id` column): one secret per git host/repo can match packages
            // across several organizations, so they stay instance-wide regardless of scope —
            // same for their audit feed below.
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
                'outgoing' => $outgoingAuditQuery->get()
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
            'organization_id' => $this->resolveCreationOrg(null),
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
        $this->assertCanTouchWebhook($webhook);

        $webhook->delete();

        return back()->with('success', 'Webhook gelöscht.');
    }

    /**
     * Aborts 403 unless the webhook is owned within the active scope. Deliberately asks
     * `scopedOrgIds()` (active scope ∩ administered orgs) rather than `assertAdministersOrg()`:
     * the latter answers "does this account administer that org at all", which is true for
     * every org once an account is a super-admin — it would let a super-admin who has
     * deliberately scoped down to one organization still delete another one's webhook. A
     * null-org (legacy) webhook is instance-wide config and is only touchable while
     * unscoped, the same visibility rule index() applies, so nothing can be deleted that
     * could not be seen.
     */
    private function assertCanTouchWebhook(Webhook $webhook): void
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return;
        }

        abort_unless(
            $webhook->organization_id !== null && in_array($webhook->organization_id, $this->scopedOrgIds(), true),
            403,
        );
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
