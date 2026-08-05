<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDomainRequest;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        // Domains hang off registries — scope both the listing and the group picker to
        // the registries of the organizations in the active scope.
        $groupIds = $this->scopeGroupQuery(Group::query())->pluck('id');

        return Inertia::render('admin/domains/Index', [
            'domains' => Domain::with('group:id,name')->whereIn('group_id', $groupIds)->orderBy('hostname')->get()
                ->map(fn (Domain $d) => [
                    'id' => $d->id,
                    'hostname' => $d->hostname,
                    'group' => $d->group?->name,
                    'group_id' => $d->group_id,
                ]),
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $group = Group::findOrFail($data['group_id']);
        $this->assertAdministersGroup($group);

        $domain = Domain::create([
            'group_id' => $group->id,
            'hostname' => $data['hostname'],
        ]);

        return back()->with('success', "Domain {$domain->hostname} hinzugefügt.");
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $this->assertAdministersOrg($domain->group?->organization_id);

        $domain->delete();

        return back()->with('success', 'Domain entfernt.');
    }
}
