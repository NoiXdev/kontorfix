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
use App\Services\Package\PackageNameKey;
use App\Services\Package\SharedAssignment;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
use App\Services\Scope\OrgScope;
use App\Support\ActivityPresenter;
use App\Support\CredentialUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * `owned_by_registry_org` mirrors clause 1 of
     * ResolvesRegistryPackage::packageExistsLocally() and of
     * Registry\PypiController::pythonExistsLocally(), which suppress the upstream for a name
     * this registry's ORGANIZATION owns, with no assignment involved at all. The availability
     * copy needs it, and `shared` cannot answer for it: a shared package assigned to a
     * registry of the operator organization satisfies both clauses, so detaching it would not
     * release the name either. Without this the notes would tell an operator to detach — a
     * destructive act — to release a name that detaching does not release, producing exactly
     * the unexplainable 404 the copy exists to prevent.
     *
     * It is an EXISTENCE question over `(type, name)`, not a property of the row, and the two
     * genuinely diverge. SharedAssignment::assertNameUnclaimedIn() reads assignedPackages(),
     * so a lapsed shared assignment does not stop the registry's own organization creating a
     * package under that name — after which the registry carries an in-force own row and a
     * lapsed shared row of one name. The lapsed shared row is the one that renders the
     * "abgelaufen" note; a per-row identity check calls it foreign and offers detaching as
     * the release path, while clause 1 goes on matching through the own package. That is the
     * destructive-and-useless instruction this field exists to prevent, on the row most
     * likely to be read.
     *
     * Python names are compared PEP 503-normalised, because pythonExistsLocally() does: an
     * own `Shared_Lib` holds the name a shared `shared-lib` carries, and the stored strings
     * never match. No SQL predicate states that, so those rows are normalised in PHP.
     *
     * The coupling is one-way and worth knowing: if either clause ever stopped keying on
     * ownership, this field and the German copy built on it would both have to follow. It is
     * asserted rather than only described, in
     * tests/Feature/Admin/AssignmentOwnershipMatchesUpstreamGuardTest.php.
     *
     * `manageable` is the console's copy of the answer both halves of spec §4 give for this
     * row: whether this operator may detach it (which would shrink the registry's shared
     * assignments, refused by
     * {@see GuardsPackageAttachment::assertSharedAssignmentsUnchanged()}) or re-date it
     * (refused by {@see GuardsPackageAttachment::assertMayEditSharedAssignment()}). The two
     * guards are separate but their answer for one row is the same question — is this a
     * shared package owned outside what the caller administers — so the flag is one boolean.
     * Without it the two row actions would sit there and answer 403, which is the same defect
     * as a picker offering what the guard refuses, on the other side of the page.
     *
     * Stated per row, from the package's owning organization, rather than as one flag for
     * the page: the guard asks per package, and a page-level flag would be a second, weaker
     * statement of it.
     *
     * A plain list rather than a Collection: Collection's TValue is invariant, so an array
     * shape in that position is rejected even against itself.
     *
     * @return list<array{id:string, name:string, type:value-of<PackageType>, sync_status:value-of<SyncStatus>, shared:bool, available_until:?string, in_force:bool, owned_by_registry_org:bool, manageable:bool}>
     */
    private function assignedPackagePayload(Group $group): array
    {
        $inForce = $group->assignedPackages()->pluck('packages.id')->all();

        $rows = $group->packages()->orderBy('name')
            // `shared` is selected explicitly: a column-restricted get() that omitted it
            // would yield null rather than fail, and the marker would silently never appear.
            // `organization_id` for the same reason — it is what `manageable` is decided on.
            ->get(['packages.id', 'name', 'type', 'sync_status', 'shared', 'packages.organization_id']);

        $ownedNames = $this->namesHeldByOrganization($group, $rows);
        $administeredOrgIds = $this->administeredOrganizationIds();

        return $rows
            ->map(function (Package $p) use ($inForce, $ownedNames, $administeredOrgIds): array {
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
                    'owned_by_registry_org' => in_array(PackageNameKey::for($p->type, $p->name), $ownedNames, true),
                    'manageable' => ! $p->shared || in_array($p->organization_id, $administeredOrgIds, true),
                ];
            })
            ->all();
    }

    /**
     * Of the names this page lists, the ones the registry's organization holds a package
     * under — the `(type, name)` existence question clause 1 of
     * ResolvesRegistryPackage::packageExistsLocally() and
     * Registry\PypiController::pythonExistsLocally() actually ask.
     *
     * Two queries, because the two resolvers match names differently and only one of them
     * can be expressed as a SQL `whereIn`:
     *
     *  - Composer and npm resolve the stored name verbatim, so the listed names filter the
     *    lookup directly.
     *  - PyPI resolves a PEP 503-normalised name, so an own `Shared_Lib` holds the name a
     *    shared `shared-lib` carries while the stored strings never match. Nothing in SQL
     *    states that normalisation, so the organization's Python packages are fetched and
     *    normalised here. Bounded by the organization's Python package count, and only run
     *    when the page actually lists a Python row.
     *
     * @param  Collection<int, Package>  $rows
     * @return list<string>
     */
    private function namesHeldByOrganization(Group $group, Collection $rows): array
    {
        // Plain arrays throughout: `except()`/`only()` on an Eloquent collection filter by
        // MODEL KEY, so applying either to the result of groupBy() calls getKey() on a
        // collection and fatals.
        $resolvedVerbatim = $rows->reject(fn (Package $p): bool => $p->type === PackageType::Python);

        $held = [];

        if ($resolvedVerbatim->isNotEmpty()) {
            $held = Package::where('organization_id', $group->organization_id)
                ->whereIn('type', $resolvedVerbatim->map(fn (Package $p): string => $p->type->value)->unique()->all())
                ->whereIn('name', $resolvedVerbatim->map(fn (Package $p): string => $p->name)->unique()->all())
                ->get(['type', 'name'])
                ->map(fn (Package $p): string => PackageNameKey::for($p->type, $p->name))
                ->all();
        }

        if ($rows->contains(fn (Package $p): bool => $p->type === PackageType::Python)) {
            $held = array_merge($held, Package::where('organization_id', $group->organization_id)
                ->where('type', PackageType::Python)
                ->get(['type', 'name'])
                ->map(fn (Package $p): string => PackageNameKey::for($p->type, $p->name))
                ->all());
        }

        return array_values(array_unique($held));
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

        // …and a shared package only arrives here if the caller may hand it out. The registry
        // does not exist yet, so it carries nothing and its post-state is the submission:
        // every shared package in it is one this write ADDS.
        $this->assertSharedAssignmentsUnchanged([], $packageIds);

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

        // syncWithoutDetaching() keeps what is already assigned, so the post-state is that
        // plus the submission. Re-submitting a shared package the registry already carries
        // therefore changes nothing and is not refused — it is not an assignment being made.
        $assigned = $this->currentAssignmentIds($group);
        $this->assertSharedAssignmentsUnchanged($assigned, array_merge($assigned, $data['package_ids']));

        // The same post-state, asked the orthogonal shadowing question — in either direction:
        // a shared package arriving over an own one, or an own one arriving over a shared
        // package already assigned.
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

        // Re-dating a SHARED assignment is a change to how long this customer receives the
        // operator's package, which spec §4 reserves to whoever administers the owning
        // organization.
        //
        // Asked HERE rather than left to assertSharedAssignmentsUnchanged(), which cannot see
        // this write at all: `available_until` is a column on the pivot row, so the set of
        // assigned package ids is identical before and after and that comparison correctly
        // finds nothing to refuse. The two halves of spec §4's sentence need two predicates.
        //
        // After the 404, so a request naming a package this registry does not carry still
        // answers "no such assignment" rather than leaking, by the choice of status code,
        // whether the package is shared.
        $this->assertMayEditSharedAssignment($package);

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

    /**
     * Ends an assignment.
     *
     * Detaching a SHARED package is the operator's act, not the receiving customer's: it
     * ends the per-customer decision spec §4 exists to express, and — per that section as
     * amended — it is also the one thing that releases the name back to the public index,
     * so a customer admin doing it unilaterally would reopen a private name to the upstream
     * for their own builds. Its own package stays entirely theirs to detach.
     */
    public function detachPackage(Group $group, Package $package): RedirectResponse
    {
        $this->assertAdministersGroup($group);

        // detach() leaves the current assignment minus this package. A package the registry
        // does not carry leaves the set alone and is the no-op it has always been.
        $assigned = $this->currentAssignmentIds($group);
        $this->assertSharedAssignmentsUnchanged(
            $assigned,
            array_values(array_diff($assigned, [(string) $package->id])),
        );

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
