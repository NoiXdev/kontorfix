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
use App\Services\RegistryAccessService;
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
        // What the registry ACTUALLY SERVES, for every `in_force` on these pages. The pages
        // used to ask Group::assignedPackages(), which is the expiry predicate ALONE; this
        // service adds the own-or-shared constraint, and the portal's landing page has always
        // decided `in_force` through it. A package the operator un-shared is lapsed for one
        // and in force for the other — two pages of one portal disagreeing about what the
        // registry serves, which is the exact defect `in_force` exists to prevent.
        private RegistryAccessService $access,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $this->portalOrganization($request);

        // The organization the URL addresses, not every organization the viewer belongs to.
        // Merging their memberships was the only answer available while the portal had a
        // single address (/portal); with /c/{orgSlug} it made the address a lie — a viewer
        // with two memberships saw the same merged list under both slugs, and an operator
        // looking at a customer's portal saw their own registries listed inside it. The
        // other organization's registries did not disappear: they live at its own slug.
        $groups = $organization->groups()
            // groups.portal_enabled: whether this registry appears in the portal. The
            // organization-level switch that decides whether there is a portal at all is a
            // different question, and ResolvePortalContext already answered it.
            ->where('portal_enabled', true)
            // `organization` too: RegistryUrl::path() reads its slug, and this is a loop.
            ->with(['domains', 'organization'])
            ->withCount('packages')
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/Registries', [
            'orgSlug' => $organization->slug,
            'registries' => $groups->map(fn (Group $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'url' => $this->url->base($g),
                // TWO NUMBERS, because the card and the registry page answer two different
                // questions and used to answer them with one number. `served_count` is what
                // this registry actually delivers — the customer's answer to "what is in
                // here", and a count that included what the registry 404s would be wrong.
                // `assigned_count` is how many rows the registry page will list, since that
                // page now shows lapsed assignments instead of hiding them. They differ
                // exactly when something has lapsed, and the card says so rather than
                // printing "1 Paket" above a page with two rows.
                //
                // packagesFor() per registry, not a withCount() with the predicate spelled
                // out again here: it is the one statement of what a registry serves, and
                // PortalPackages::for() already reads it in a loop over the same groups.
                'served_count' => $this->access->packagesFor($g)->count(),
                'assigned_count' => $g->packages_count,
            ]),
        ]);
    }

    public function show(Request $request, Group $group): Response
    {
        $organization = $this->portalOrganization($request);
        $this->authorize('view', $group);
        // The address has to BIND. GroupPolicy::view() asks only whether the viewer belongs
        // to the group's organization, never whether the group belongs to the organization
        // the URL names — so a member of both A and B was served B's registry, B's setup
        // snippets and B's package list under /c/A, with `orgSlug` = A in every link and
        // breadcrumb on the page. An operator account, which may open any customer's portal,
        // could reach any customer's registry from inside any other one.
        //
        // 403 and not 404: the uniform-404 rule protects organization SLUGS from being
        // enumerated one response code at a time, and this compares UUIDs a guesser does not
        // have. It is also the answer PortalIsolationTest and RegistryPortalTest already
        // expect for a foreign registry.
        abort_unless($group->organization_id === $organization->id, 403);
        // `groups.portal_enabled` — whether this registry appears in the portal at all, the
        // same predicate index() and PortalPackages ask of the same column.
        //
        // HERE AND NOT ONLY IN THE POLICY, because a policy is the wrong home for it and the
        // repo says so: AppServiceProvider's Gate::before short-circuits every policy for a
        // super-admin, so GroupPolicy::view() never runs for that population and its copy of
        // this check never fires. That is correct for an AUTHORIZATION question — which this
        // is not. It is a property of the surface, not of the viewer: the registry is one the
        // portal does not show, to anybody. Stated in the controller it runs for every caller,
        // needs no change to a repo-wide bypass, and sits beside the other statement of what
        // this portal shows.
        //
        // 404, not the policy's 403: a registry the portal does not have is absent, not
        // forbidden. The policy still answers 403 for the viewer populations it refuses
        // first — the two guards refuse different people by different mechanisms, and the
        // tests pin both.
        abort_unless($group->portal_enabled, 404);
        $group->load(['domains', 'organization']);

        // Load versions descending by released_at and pick the newest one in PHP —
        // NO limit(1) in the eager load (that would constrain across all packages, not per package).
        // Qualify columns because of the belongsToMany join (packages.*), to avoid ambiguity.
        // The full (unpaginated) list is sent to the client, which does its own
        // search/type filtering via useTableState (prefix 'pkg') — no server-side
        // pre-filter here, so there is no bare q/type param that could silently and
        // invisibly narrow the list with no way to see or reset it from the UI.
        //
        // Every assignment, each saying whether the registry still serves it — the shape
        // Admin\GroupController::assignedPackagePayload() sends, so the operator and the
        // customer read one answer. Hiding a lapsed row was the previous answer, taken while
        // the portal had no way to describe one; a customer whose build 404s then arrived at
        // a page that did not list the package at all, which reads as "never there" rather
        // than as "it lapsed". The row is shown and marked instead.
        //
        // `in_force` comes from RegistryAccessService — the single statement of what a
        // registry serves, and the one the registry endpoints themselves answer by. NOT from
        // Group::assignedPackages(): that relation carries the expiry predicate alone, while
        // what is served is expiry AND own-or-shared. A package the operator un-shared has no
        // date at all, so an expiry-only answer calls it in force and offers an install
        // snippet that 404s — while /c/{org} calls the same package lapsed, because
        // PortalPackages has always asked this service. NOT from comparing `available_until`
        // here either: a second statement of the rule is what this avoids.
        $inForce = $this->access->packagesFor($group)->keyBy('id');

        $packages = $group->packages()
            ->with(['versions' => fn ($q) => $q->orderByDesc('released_at')])
            ->orderBy('packages.name')
            ->get();

        return Inertia::render('portal/Registry', [
            'orgSlug' => $organization->slug,
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
                // The two flags portalPackages.ts turns into row markers, under the names it
                // reads them by — the page renders the same badges as portal/Packages.vue
                // through the same module rather than a second copy of the rule.
                'shared' => $p->shared,
                'in_force' => $inForce->has($p->id),
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
        $organization = $this->portalOrganization($request);
        $this->authorize('view', $group);
        // Binds the address to the registry, and refuses a registry the portal does not
        // show — see show(), which carries the reasoning for both.
        abort_unless($group->organization_id === $organization->id, 403);
        abort_unless($group->portal_enabled, 404);
        // packages(), not assignedPackages(): the question here is whether this registry has
        // this assignment AT ALL. A package assigned to no registry still 404s — that is a
        // guessed URL, and the customer has nothing to be told about it. A lapsed one is a
        // different case: the customer's build is failing on exactly this package, and the
        // page they reach for the explanation used to answer 404 as well, which explains
        // nothing. It is served, with `in_force` false, and says why.
        abort_unless($group->packages()->whereKey($package->id)->exists(), 404);

        // packageBelongsToGroup(), the same service show() asks: what the registry serves,
        // not merely what has not expired.
        $inForce = $this->access->packageBelongsToGroup($group, $package);

        $package->load('versions');
        $package->setRelation('versions', VersionOrder::sort($package->versions));

        $install = $package->type->installHint($package->name);

        return Inertia::render('portal/Package', [
            'orgSlug' => $organization->slug,
            'registry' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'url' => $this->url->base($group),
            ],
            'package' => [
                // The page's breadcrumb links to the package's own address, so it needs the
                // id the URL is built from.
                'id' => $package->id,
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
            // Whether this registry still serves the package. The page replaces the install
            // snippet with the explanation when it does not — offering a command that
            // answers 404 is worse than saying nothing.
            'in_force' => $inForce,
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
