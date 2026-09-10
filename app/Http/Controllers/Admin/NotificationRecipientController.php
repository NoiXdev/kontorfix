<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNotificationRecipientRequest;
use App\Models\NotificationRecipient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationRecipientController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        // organization_id is a mandatory FK here (unlike Webhook's legacy nullable column),
        // so a plain whereIn is enough: scopedOrgIds() already returns every organization for
        // a super-admin viewing "all orgs", with no null-org row to special-case.
        return Inertia::render('admin/notification-recipients/Index', [
            'recipients' => NotificationRecipient::with('organization:id,name')
                ->whereIn('organization_id', $this->scopedOrgIds())
                ->latest()->get()
                ->map(fn (NotificationRecipient $r) => [
                    'id' => $r->id,
                    'email' => $r->email,
                    'name' => $r->name,
                    'events' => $r->events ?? [],
                    'enabled' => $r->enabled,
                    'organization' => $r->organization?->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/notification-recipients/Create');
    }

    public function store(StoreNotificationRecipientRequest $request): RedirectResponse
    {
        $data = $request->validated();

        NotificationRecipient::create([
            'organization_id' => $this->resolveCreationOrg(null),
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'events' => $data['events'] ?? [],
            'enabled' => $data['enabled'] ?? true,
        ]);

        // Explicitly to the index, not back(): the form now lives on its own
        // `admin/notification-recipients/create` page, and back() would return there — to a
        // freshly emptied form that renders no `flash.success`. The index shows the new row
        // and the flash.
        return redirect()->route('admin.notification-recipients.index')->with('success', 'Empfänger erstellt.');
    }

    public function destroy(NotificationRecipient $notificationRecipient): RedirectResponse
    {
        // scopedOrgIds() (active scope ∩ administered orgs), not assertAdministersOrg(): the
        // latter is true for every organization once an account is a super-admin, which would
        // let one who has deliberately scoped down to a single organization still delete
        // another organization's recipient — see WebhookController::assertCanTouchWebhook()
        // for the identical reasoning.
        abort_unless(in_array($notificationRecipient->organization_id, $this->scopedOrgIds(), true), 403);

        $notificationRecipient->delete();

        return back()->with('success', 'Empfänger gelöscht.');
    }
}
