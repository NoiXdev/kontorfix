<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNotificationRecipientRequest;
use App\Models\NotificationRecipient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationRecipientController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/notification-recipients/Index', [
            'recipients' => NotificationRecipient::latest()->get()
                ->map(fn (NotificationRecipient $r) => [
                    'id' => $r->id,
                    'email' => $r->email,
                    'name' => $r->name,
                    'events' => $r->events ?? [],
                    'enabled' => $r->enabled,
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
            'organization_id' => $request->user()->organization_id,
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'events' => $data['events'] ?? [],
            'enabled' => $data['enabled'] ?? true,
        ]);

        return back()->with('success', 'Empfänger erstellt.');
    }

    public function destroy(NotificationRecipient $notificationRecipient): RedirectResponse
    {
        $notificationRecipient->delete();

        return back()->with('success', 'Empfänger gelöscht.');
    }
}
