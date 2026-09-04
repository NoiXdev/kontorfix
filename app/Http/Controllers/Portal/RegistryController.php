<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use App\Services\Package\PackageDependencies;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
use App\Support\VersionOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistryController extends Controller
{
    public function __construct(
        private RegistryUrl $url,
        private SetupSnippetBuilder $snippets,
        private PackageDependencies $dependencies,
    ) {}

    public function index(Request $request): Response
    {
        // Show registries from every organization the user belongs to (home org plus
        // any additional memberships), not just their home org.
        $groups = Group::whereIn('organization_id', $request->user()->accessibleOrganizationIds())
            ->where('portal_enabled', true)
            // `organization` too: RegistryUrl::path() reads its slug, and this is a loop.
            ->with(['domains', 'organization'])
            // assignedPackages(), not packages(): this count is the customer's answer to
            // "what is in this registry", and an assignment past its `available_until`
            // serves nothing. Counting the pivot rows made a lapsed share look live here
            // while `composer install` answered 404 — the same console-disagrees-with-the-
            // registry defect `in_force` was added to fix on the admin page, on the page the
            // customer actually reads.
            ->withCount('assignedPackages')
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/Registries', [
            'orgSlug' => $this->portalOrganization($request)->slug,
            'registries' => $groups->map(fn (Group $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'url' => $this->url->base($g),
                'packages_count' => $g->assigned_packages_count,
            ]),
        ]);
    }

    public function show(Request $request, Group $group): Response
    {
        $this->authorize('view', $group);
        $group->load(['domains', 'organization']);

        // Load versions descending by released_at and pick the newest one in PHP —
        // NO limit(1) in the eager load (that would constrain across all packages, not per package).
        // Qualify columns because of the belongsToMany join (packages.*), to avoid ambiguity.
        // The full (unpaginated) list is sent to the client, which does its own
        // search/type filtering via useTableState (prefix 'pkg') — no server-side
        // pre-filter here, so there is no bare q/type param that could silently and
        // invisibly narrow the list with no way to see or reset it from the UI.
        //
        // assignedPackages(): see index(). The portal must list what the registry serves —
        // a lapsed assignment appeared here with its latest version and no marker of any
        // kind, and this page has no "abgelaufen" badge to explain one.
        $packages = $group->assignedPackages()
            ->with(['versions' => fn ($q) => $q->orderByDesc('released_at')])
            ->orderBy('packages.name')
            ->get();

        return Inertia::render('portal/Registry', [
            'orgSlug' => $this->portalOrganization($request)->slug,
            'registry' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'url' => $this->url->base($group),
            ],
            'snippets' => $this->snippets->for($group),
            'packages' => $packages->map(fn (Package $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type->value,
                'description' => $p->description,
                'latest_version' => $p->versions->first()?->version_pretty,
            ]),
            'tokens' => $group->tokens()->where('user_id', $request->user()->id)->latest()->get()->map(fn (RegistryToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'ability' => $t->ability->value,
                'last_used_at' => $t->last_used_at?->diffForHumans(),
                // Raw ISO timestamp for sorting only — `last_used_at` above is a relative
                // string ("vor 3 Tagen") that Date.parse cannot read, so the display value
                // and the sort value have to travel separately.
                'last_used_at_iso' => $t->last_used_at?->toIso8601String(),
            ]),
        ]);
    }

    public function showPackage(Request $request, Group $group, Package $package): Response
    {
        $this->authorize('view', $group);
        // assignedPackages(): the detail page served the readme, the version list and the
        // dependency tree for an assignment that had lapsed, while the registry answered 404
        // for the same package. 404 here is the same answer the registry gives.
        abort_unless($group->assignedPackages()->whereKey($package->id)->exists(), 404);

        $package->load('versions');
        $package->setRelation('versions', VersionOrder::sort($package->versions));

        $install = $package->type->installHint($package->name);

        return Inertia::render('portal/Package', [
            'orgSlug' => $this->portalOrganization($request)->slug,
            'registry' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'url' => $this->url->base($group),
            ],
            'package' => [
                'type' => $package->type->value,
                'name' => $package->name,
                'description' => $package->description,
                'readme_html' => $package->readme_html,
                'sync_status' => $package->sync_status->value,
                'abandoned_at' => $package->abandoned_at?->toDateString(),
                'replacement_package' => $package->replacement_package,
                'abandonment_reason' => $package->abandonment_reason,
            ],
            'versions' => $package->versions->map(fn (PackageVersion $v) => [
                'version' => $v->version_pretty ?? $v->version,
                'released_at' => $v->released_at?->toDateString(),
                'dependencies' => $this->dependencies->for($package->type, $v->metadata ?? []),
            ]),
            'install' => $install,
        ]);
    }

    /**
     * The organization the URL addresses, put on the request by ResolvePortalContext.
     * Every portal page needs its slug: it is the first segment of every portal URL the
     * page builds, so it travels to the client as a prop rather than being re-derived
     * there from window.location.
     */
    private function portalOrganization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('portalOrganization');

        return $organization;
    }
}
