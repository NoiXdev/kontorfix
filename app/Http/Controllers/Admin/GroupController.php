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
use App\Services\Package\SharedAssignment;
use App\Services\Registry\RegistryUrl;
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

    public function index(RegistryUrl $url): Response
    {
        // Only registries of organizations the user may administer (and within the
        // active sidebar scope). A super-admin's scope spans every organization.
        return Inertia::render('admin/groups/Index', [
            'groups' => $this->scopeGroupQuery(
                // `slug` alongside `name`: see show() below — a column-restricted eager
                // load that omits it yields a null slug rather than an error, so any URL
                // built from this payload would silently come out as /r//{groupSlug}.
                Group::withCount('packages')->with(['domains:id,group_id,hostname', 'organization:id,name,slug'])
            )->orderBy('name')->get()
                ->map(fn (Group $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'slug' => $g->slug,
                    // The address the operator would paste into a client, stated by the
                    // application rather than re-derived from `slug` in the table cell.
                    'url_path' => $url->path($g),
                    'public' => $g->public,
                    'portal_enabled' => $g->portal_enabled,
                    'packages_count' => $g->packages_count,
                    'domains' => $g->domains->pluck('hostname'),
                    'organization' => $g->organization?->name,
                    'organization_id' => $g->organization_id,
                ]),
            // The org picker only offers organizations the user may create registries in.
            'organizations' => app(OrgScope::class)->organizations(),
            // The URL form with both slugs left open — the create sheet previews an address
            // for a registry that does not exist yet and must not invent the form for it.
            // The path only: the sheet shows it against the browser's own origin, which is
            // the host the operator is actually talking to.
            'registryUrlTemplate' => $url->template(),
        ]);
    }

    public function show(Group $group, SetupSnippetBuilder $snippets, RegistryUrl $url): Response
    {
        $this->assertAdministersGroup($group);

        // `slug` on the organization is load-bearing, not decoration: the setup snippets
        // address the registry as /r/{orgSlug}/{groupSlug} via RegistryUrl.
        $group->load(['organization:id,name,slug', 'domains:id,group_id,hostname', 'upstreams', 'tokens']);

        return Inertia::render('admin/groups/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'public' => $group->public,
                'portal_enabled' => $group->portal_enabled,
                'organization' => $group->organization?->name,
                'organization_id' => $group->organization_id,
                // Three statements of the same URL form, all of them made here: the path as
                // rendered in the header, the canonical URL as it stands today, and that URL
                // with the slug left open so the confirmation dialog can show what a change
                // would turn it into. Deliberately canonical() and not base(): a registry on
                // a custom domain still moves its /r/… address when the slug changes, and a
                // dialog built from the domain URL would show the same string twice.
                'url_path' => $url->path($group),
                'url' => $url->canonical($group),
                'url_pattern' => $url->pattern($group),
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

    public function store(StoreGroupRequest $request, SharedAssignment $sharedAssignment): RedirectResponse
    {
        // The organization is the active scope (or, viewing "all", the explicitly chosen
        // one) — always validated to be one the user may administer.
        $organizationId = $this->resolveCreationOrg($request->validated('organization_id'));

        // Packages may only be pulled in from this registry's own organization — never
        // out of another one.
        $packageIds = $request->validated('package_ids', []);
        $this->assertCanAttachPackages($packageIds, $organizationId);

        // …and a shared package may not be seeded alongside the own package it would
        // shadow. Asked before the insert, so a refusal leaves no empty registry behind.
        $sharedAssignment->assertCreatable($packageIds);

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
            // Present only when the request actually carried a slug (see UpdateGroupRequest).
            // Changing it moves the registry's URL and breaks client configurations pointing
            // at the old one, which is why the console confirms before it submits.
            ...$request->safe()->only('slug'),
        ]);

        return back()->with('success', 'Registry aktualisiert.');
    }

    public function attachPackages(Request $request, Group $group, SharedAssignment $sharedAssignment): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        $data = $request->validate([
            'package_ids' => ['required', 'array', 'min:1'],
            'package_ids.*' => ['uuid', 'exists:packages,id'],
        ]);

        $this->assertCanAttachPackages($data['package_ids'], $group->organization_id);
        $sharedAssignment->assertAssignable($group, $data['package_ids']);

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
