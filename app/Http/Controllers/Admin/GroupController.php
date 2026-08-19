<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use App\Models\Upstream;
use App\Services\Registry\SetupSnippetBuilder;
use App\Services\Scope\OrgScope;
use App\Support\ActivityPresenter;
use App\Support\CredentialUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        // Only registries of organizations the user may administer (and within the
        // active sidebar scope). A super-admin's scope spans every organization.
        return Inertia::render('admin/groups/Index', [
            'groups' => $this->scopeGroupQuery(
                Group::withCount('packages')->with(['domains:id,group_id,hostname', 'organization:id,name'])
            )->orderBy('name')->get()
                ->map(fn (Group $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'slug' => $g->slug,
                    'public' => $g->public,
                    'portal_enabled' => $g->portal_enabled,
                    'packages_count' => $g->packages_count,
                    'domains' => $g->domains->pluck('hostname'),
                    'organization' => $g->organization?->name,
                    'organization_id' => $g->organization_id,
                ]),
            // The org picker only offers organizations the user may create registries in.
            'organizations' => app(OrgScope::class)->organizations(),
        ]);
    }

    public function show(Group $group, SetupSnippetBuilder $snippets): Response
    {
        $this->assertAdministersGroup($group);

        $group->load(['organization:id,name', 'domains:id,group_id,hostname', 'upstreams', 'tokens']);

        return Inertia::render('admin/groups/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'public' => $group->public,
                'portal_enabled' => $group->portal_enabled,
                'organization' => $group->organization?->name,
                'organization_id' => $group->organization_id,
            ],
            // The belongsToMany join makes `id` ambiguous — hence qualify packages.id.
            'packages' => $group->packages()->orderBy('name')->get(['packages.id', 'name', 'type', 'sync_status'])
                ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value, 'sync_status' => $p->sync_status->value]),
            'domains' => $group->domains->map(fn (Domain $d) => ['id' => $d->id, 'hostname' => $d->hostname]),
            'upstreams' => $group->upstreams->map(fn (Upstream $u) => ['id' => $u->id, 'type' => $u->type->value, 'url' => CredentialUrl::redact($u->url), 'policy' => $u->policy->value]),
            'tokens' => $group->tokens->map(fn (RegistryToken $t) => ['id' => $t->id, 'name' => $t->name, 'ability' => $t->ability->value, 'last_used_at' => $t->last_used_at?->diffForHumans()]),
            'setup' => $snippets->for($group),
            'stats' => $this->groupStats($group),
            'activities' => ActivityPresenter::recentFor($group),
        ]);
    }

    /**
     * Registry-level rollup of the usage stats of all its packages' versions.
     *
     * @return array{downloads:int, storage_bytes:int, packages:int}
     */
    private function groupStats(Group $group): array
    {
        $packageIds = $group->packages()->pluck('packages.id');

        $agg = PackageVersion::whereIn('package_id', $packageIds)
            ->selectRaw('COALESCE(SUM(download_count),0) as downloads, COALESCE(SUM(dist_size),0) as storage_bytes')
            ->first();

        return [
            'downloads' => (int) ($agg->downloads ?? 0),
            'storage_bytes' => (int) ($agg->storage_bytes ?? 0),
            'packages' => $packageIds->count(),
        ];
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        // The organization is the active scope (or, viewing "all", the explicitly chosen
        // one) — always validated to be one the user may administer.
        $organizationId = $this->resolveCreationOrg($request->validated('organization_id'));

        // Packages may only be pulled in from the caller's own reach — never out of
        // another organization's registry.
        $packageIds = $request->validated('package_ids', []);
        $this->assertCanAttachPackages($packageIds);

        $group = Group::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
            'public' => $request->boolean('public'),
            'portal_enabled' => $request->boolean('portal_enabled'),
            'organization_id' => $organizationId,
        ]);
        $group->packages()->sync($packageIds);

        return back()->with('success', "Gruppe {$group->name} erstellt.");
    }

    public function update(UpdateGroupRequest $request, Group $group): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        $group->update([
            'name' => $request->validated('name'),
            'public' => $request->boolean('public'),
            'portal_enabled' => $request->boolean('portal_enabled'),
        ]);

        return back()->with('success', 'Registry aktualisiert.');
    }

    public function attachPackages(Request $request, Group $group): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        $data = $request->validate([
            'package_ids' => ['required', 'array', 'min:1'],
            'package_ids.*' => ['uuid', 'exists:packages,id'],
        ]);

        $this->assertCanAttachPackages($data['package_ids']);

        // syncWithoutDetaching keeps the packages already in the group.
        $group->packages()->syncWithoutDetaching($data['package_ids']);

        return back()->with('success', 'Pakete zur Registry hinzugefügt.');
    }

    public function detachPackage(Group $group, Package $package): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        $group->packages()->detach($package->id);

        return back()->with('success', 'Paket aus der Registry entfernt.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        $group->delete();

        return back()->with('success', 'Gruppe gelöscht.');
    }
}
