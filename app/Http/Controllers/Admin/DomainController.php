<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDomainRequest;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/domains/Index', [
            'domains' => Domain::with('group:id,name')->orderBy('hostname')->get()
                ->map(fn (Domain $d) => [
                    'id' => $d->id,
                    'hostname' => $d->hostname,
                    'group' => $d->group?->name,
                    'group_id' => $d->group_id,
                ]),
            'groups' => Group::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $domain = Domain::create([
            'group_id' => $data['group_id'],
            'hostname' => $data['hostname'],
        ]);

        return back()->with('success', "Domain {$domain->hostname} hinzugefügt.");
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $domain->delete();

        return back()->with('success', 'Domain entfernt.');
    }
}
