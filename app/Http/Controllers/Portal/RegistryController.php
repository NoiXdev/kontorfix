<?php

namespace App\Http\Controllers\Portal;

use App\Enums\PackageType;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use App\Services\Package\PackageDependencies;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
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
        $groups = Group::where('organization_id', $request->user()->organization_id)
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

        // Versionen absteigend nach released_at laden und in PHP die neueste greifen —
        // KEIN limit(1) im Eager-Load (constrained das über alle Pakete hinweg, nicht pro Paket).
        $packages = $group->packages()
            ->with(['versions' => fn ($q) => $q->orderByDesc('released_at')])
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/Registry', [
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
            'tokens' => $group->tokens()->latest()->get()->map(fn (RegistryToken $t) => [
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

        $package->load(['versions' => fn ($q) => $q->orderByDesc('released_at')]);

        $name = $package->name;
        $install = $package->type === PackageType::Npm
            ? "npm install {$name}"
            : "composer require {$name}";

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
