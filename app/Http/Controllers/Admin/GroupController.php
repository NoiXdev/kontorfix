<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Models\Domain;
use App\Models\Group;
use App\Models\GroupPackage;
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
use Carbon\CarbonImmutable;
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
            //
            // packages(), not assignedPackages(): an expired assignment must stay on this
            // page. Detaching is the only thing that ends it — and, per spec §4, the only
            // thing that releases the name back to the public index — and the operator
            // cannot detach a row the page hides. What it must not do is show the row as
            // though it were live, which is what it did before `in_force` existed: the
            // operator read "assigned" while the registry served nothing and the customer's
            // build got a 404 with nothing on this page to explain it.
            'packages' => $this->assignedPackagePayload($group),
            // The application's own calendar day, for the availability editor.
            //
            // Stated by the server rather than read off the browser clock, because the two
            // can disagree: the application runs in UTC (config/app.php) and a browser west
            // of it is on the previous day for several hours. The editor uses this only to
            // tell the operator whether the date they picked takes effect immediately, and
            // that answer has to be the one the stored `available_until` will actually
            // produce.
            'today' => now()->toDateString(),
            'domains' => $group->domains->map(fn (Domain $d) => ['id' => $d->id, 'hostname' => $d->hostname]),
            'upstreams' => $group->upstreams->map(fn (Upstream $u) => ['id' => $u->id, 'type' => $u->type->value, 'url' => CredentialUrl::redact($u->url), 'policy' => $u->policy->value]),
            'tokens' => $group->tokens->map(fn (RegistryToken $t) => ['id' => $t->id, 'name' => $t->name, 'ability' => $t->ability->value, 'last_used_at' => $t->last_used_at?->diffForHumans()]),
            'setup' => $snippets->for($group),
            'stats' => $this->groupStats($group),
            'activities' => ActivityPresenter::recentFor($group),
        ]);
    }

    /**
     * Every assignment of this registry, each saying whether the registry actually serves
     * it and until when.
     *
     * `in_force` is decided by Group::assignedPackages() — the single statement of the
     * expiry predicate, the same one RegistryAccessService serves with — rather than by
     * comparing dates here. A second statement of it could disagree with the registry, and
     * the console disagreeing with the registry about precisely this is the defect being
     * fixed.
     *
     * `available_until` goes out as a plain day: the editor writes a day, and the column is
     * the last moment of that day (see updateAssignment()), so the day is the value the
     * operator gave and the one the date field has to be seeded with.
     *
     * A plain list rather than a Collection: Collection's TValue is invariant, so an array
     * shape in that position is rejected even against itself.
     *
     * @return list<array{id:string, name:string, type:value-of<PackageType>, sync_status:value-of<SyncStatus>, shared:bool, available_until:?string, in_force:bool}>
     */
    private function assignedPackagePayload(Group $group): array
    {
        $inForce = $group->assignedPackages()->pluck('packages.id')->all();

        return $group->packages()->orderBy('name')
            // `shared` is selected explicitly: a column-restricted get() that omitted it
            // would yield null rather than fail, and the marker would silently never appear.
            ->get(['packages.id', 'name', 'type', 'sync_status', 'shared'])
            ->map(function (Package $p) use ($inForce): array {
                // The pivot row this package was loaded through. Read via getRelation()
                // rather than `$p->pivot`, which is set dynamically by the belongsToMany
                // and so is invisible to static analysis on a plain Package.
                $pivot = $p->getRelation('pivot');

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type->value,
                    'sync_status' => $p->sync_status->value,
                    'shared' => $p->shared,
                    'available_until' => $pivot instanceof GroupPackage
                        ? $pivot->available_until?->toDateString()
                        : null,
                    'in_force' => in_array($p->id, $inForce, true),
                ];
            })
            ->all();
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

        // …and the registry must not end up serving a shared package under a name one of
        // its own carries. sync() on a registry that does not exist yet leaves exactly the
        // submission behind, so that is the whole post-state. Asked before the insert, so a
        // refusal leaves no empty registry behind.
        $sharedAssignment->assertReplacementAssignable($packageIds);

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
        // syncWithoutDetaching() keeps what is already assigned, so the post-state is
        // that plus the submission — in either direction: a shared package arriving over
        // an own one, or an own one arriving over a shared package already assigned.
        $sharedAssignment->assertAssignable($group, $data['package_ids']);

        // syncWithoutDetaching keeps the packages already in the group.
        $group->packages()->syncWithoutDetaching($data['package_ids']);

        return back()->with('success', 'Pakete zur Registry hinzugefügt.');
    }

    /**
     * Set or clear an assignment's `available_until` — the time-limited share of spec §6.
     *
     * This is the write path Group::assignedPackages()'s docblock warned about. Nothing in
     * the application wrote this column before, and SharedAssignment's tolerance of expired
     * rows rests on that: an expired assignment serves nothing, holds no name, and so does
     * not block assigning or creating an own package under it. Pushing a lapsed shared
     * assignment back into the future reverses that decision retroactively, and does it
     * through a request that attaches nothing — passing none of the six membership writers'
     * guards. So this one asks the same question they do.
     *
     * The post-state is syncWithoutDetaching()'s: what the registry serves now, plus this
     * assignment back in force. assertAssignable() computes exactly that. The submitted
     * package may also already be in force, in which case it appears on both sides of the
     * union — harmless, since the predicate asks whether a name carries a shared and a
     * non-shared row and a duplicate contributes the same `shared` value twice.
     *
     * The guard runs on EVERY write, with no condition on the date. A rule phrased as "only
     * when this leaves the assignment in force" has a direction and can forget a case; this
     * one has none. It is conservative rather than wrong for a write that leaves the
     * assignment lapsed: such a write adds nothing to what the registry serves and so cannot
     * collide, but assertAssignable() counts the submission in regardless. The only action
     * that costs is re-dating an already-lapsed row in a registry that already carries an
     * own package of that name — a no-op edit in a state the operator should be resolving by
     * detaching anyway.
     *
     * A DATE IN THE PAST IS ACCEPTED, and is the point rather than an oversight. Since spec
     * §4's amendment, expiring and detaching no longer have the same outcome: a lapsed
     * assignment stops delivery but keeps the name suppressed against the upstream, while
     * detaching releases it. "Stop serving this now, and keep the name blocked" is the safe
     * way to withdraw a share, and refusing a past date would leave no way to express it —
     * the operator could only wait for the end of the day in the application's timezone, or
     * detach, which is exactly the act §4 warns against.
     *
     * Reachability is not re-asserted here. The pivot row already exists, so it passed
     * assertCanAttachPackages() when it was written, and un-sharing is refused while any
     * cross-organization assignment survives — so a foreign row still implies a shared
     * package. Adding the check back would also mask the guard below in tests: a refusal
     * would land on the org scope before ever reaching it, the lesson Task 2 recorded.
     */
    public function updateAssignment(Request $request, Group $group, Package $package, SharedAssignment $sharedAssignment): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        // Without this the request would silently succeed against no row at all —
        // updateExistingPivot() reports zero affected rows and returns.
        abort_unless($group->packages()->whereKey($package->id)->exists(), 404);

        $data = $request->validate([
            // A day, not an instant: the operator picks a date and the label says
            // "verfügbar bis" it. `present` so clearing the date is an explicit act rather
            // than an omitted field. No lower bound — see the note above on why a past date
            // is a legitimate, and the safest, way to withdraw a share.
            'available_until' => ['present', 'nullable', 'date_format:Y-m-d'],
        ]);

        $sharedAssignment->assertAssignable($group, [$package->id]);

        $group->packages()->updateExistingPivot($package->id, [
            // Through the END of the named day. "Verfügbar bis 31.12." reads as inclusive,
            // and Group::assignedPackages() compares `available_until > now()`, so the first
            // moment of the day would stop serving it a day before the label promises.
            'available_until' => $data['available_until'] === null
                ? null
                : CarbonImmutable::parse($data['available_until'])->endOfDay(),
        ]);

        return back()->with('success', 'Verfügbarkeit der Zuweisung aktualisiert.');
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
