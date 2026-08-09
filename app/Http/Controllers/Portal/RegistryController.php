<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Group;
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
            ->with('domains')
            ->withCount('packages')
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/Registries', [
            'registries' => $groups->map(fn (Group $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'url' => $this->url->base($g),
                'packages_count' => $g->packages_count,
            ]),
        ]);
    }

    public function show(Request $request, Group $group): Response
    {
        $this->authorize('view', $group);
        $group->load('domains');

        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        // Load versions descending by released_at and pick the newest one in PHP —
        // NO limit(1) in the eager load (that would constrain across all packages, not per package).
        // Qualify columns because of the belongsToMany join (packages.*), to avoid ambiguity.
        $packages = $group->packages()
            ->when($q !== '', fn ($query) => $query->where('packages.name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm', 'python'], true), fn ($query) => $query->where('packages.type', $type))
            ->with(['versions' => fn ($q) => $q->orderByDesc('released_at')])
            ->orderBy('packages.name')
            ->get();

        return Inertia::render('portal/Registry', [
            'registry' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'url' => $this->url->base($group),
            ],
            'filters' => [
                'q' => $q,
                'type' => $type,
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
            ]),
        ]);
    }

    public function showPackage(Request $request, Group $group, Package $package): Response
    {
        $this->authorize('view', $group);
        abort_unless($group->packages()->whereKey($package->id)->exists(), 404);

        $package->load('versions');
        $package->setRelation('versions', VersionOrder::sort($package->versions));

        $install = $package->type->installHint($package->name);

        return Inertia::render('portal/Package', [
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
            ],
            'versions' => $package->versions->map(fn (PackageVersion $v) => [
                'version' => $v->version_pretty ?? $v->version,
                'released_at' => $v->released_at?->toDateString(),
                'dependencies' => $this->dependencies->for($package->type, $v->metadata ?? []),
            ]),
            'install' => $install,
        ]);
    }
}
