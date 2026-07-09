<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\Upstream;
use App\Services\Registry\SetupSnippetBuilder;
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

    public function show(Group $group, SetupSnippetBuilder $snippets): Response
    {
        $group->load(['organization:id,name', 'domains:id,group_id,hostname', 'upstreams', 'tokens']);

        return Inertia::render('admin/groups/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'public' => $group->public,
                'organization' => $group->organization?->name,
                'organization_id' => $group->organization_id,
            ],
            // Der belongsToMany-Join macht `id` mehrdeutig — daher packages.id qualifizieren.
            'packages' => $group->packages()->orderBy('name')->get(['packages.id', 'name', 'type', 'sync_status'])
                ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value, 'sync_status' => $p->sync_status->value]),
            'domains' => $group->domains->map(fn (Domain $d) => ['id' => $d->id, 'hostname' => $d->hostname]),
            'upstreams' => $group->upstreams->map(fn (Upstream $u) => ['id' => $u->id, 'type' => $u->type->value, 'url' => $u->url, 'policy' => $u->policy->value]),
            'tokens' => $group->tokens->map(fn (RegistryToken $t) => ['id' => $t->id, 'name' => $t->name, 'ability' => $t->ability->value, 'last_used_at' => $t->last_used_at?->diffForHumans()]),
            'setup' => $snippets->for($group),
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

    public function update(UpdateGroupRequest $request, Group $group): RedirectResponse
    {
        $group->update(['name' => $request->validated('name'), 'public' => $request->boolean('public')]);

        return back()->with('success', 'Registry aktualisiert.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $group->delete();

        return back()->with('success', 'Gruppe gelöscht.');
    }
}
