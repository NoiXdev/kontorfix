<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Models\Group;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function index(): Response
    {
        // TODO(multi-tenant): auf organization_id des Users einschränken, sobald Kunden-Admins existieren.
        return Inertia::render('admin/groups/Index', [
            'groups' => Group::withCount('packages')
                ->with(['domains:id,group_id,hostname', 'organization:id,name'])
                ->orderBy('name')->get()
                ->map(fn (Group $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'slug' => $g->slug,
                    'public' => $g->public,
                    'packages_count' => $g->packages_count,
                    'domains' => $g->domains->pluck('hostname'),
                    'organization' => $g->organization?->name,
                ]),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $group = Group::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
            'public' => $request->boolean('public'),
            'organization_id' => $request->validated('organization_id') ?? $request->user()->organization_id,
        ]);
        $group->packages()->sync($request->validated('package_ids', []));

        return back()->with('success', "Gruppe {$group->name} erstellt.");
    }

    public function destroy(Group $group): RedirectResponse
    {
        $group->delete();

        return back()->with('success', 'Gruppe gelöscht.');
    }
}
