<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PythonDist;
use App\Services\Package\PackageDependencies;
use App\Services\Registry\RegistryTypeService;
use App\Services\Vcs\RepositoryProbe;
use App\Support\ActivityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $status = $request->query('status');
        $group = $request->query('group');

        $packages = $this->scopePackageQuery(Package::query())
            ->withCount('groups')
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
            ->when(in_array($status, ['pending', 'syncing', 'synced', 'failed'], true), fn ($query) => $query->where('sync_status', $status))
            ->when(is_string($group) && $group !== '', fn ($query) => $query->whereHas('groups', fn ($g) => $g->whereKey($group)))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Package $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'sync_status' => $p->sync_status,
                'sync_error' => $p->sync_error,
                'groups_count' => $p->groups_count,
                'synced_at' => $p->synced_at?->diffForHumans(),
            ]);

        return Inertia::render('admin/packages/Index', [
            'packages' => $packages,
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'slug']),
            'filters' => ['q' => $q, 'type' => $type, 'status' => $status, 'group' => $group],
            // Only instance-enabled registry types are offered when creating a package.
            'registryTypes' => app(RegistryTypeService::class)->globalTypes(),
        ]);
    }

    public function show(Package $package, PackageDependencies $deps): Response
    {
        $this->assertCanTouchPackage($package);

        $package->load(['versions', 'groups:id,name,slug']);

        // Python is file-centric (multiple dists per version), so its "versions" and stats
        // come from the python_dists table rather than package_versions.
        $isPython = $package->type === PackageType::Python;
        $dists = $isPython ? $package->pythonDists()->orderByDesc('uploaded_at')->get() : collect();

        return Inertia::render('admin/packages/Show', [
            'package' => [
                'id' => $package->id,
                'type' => $package->type->value,
                'name' => $package->name,
                'description' => $package->description,
                'repository_url' => $package->repository_url,
                'sync_status' => $package->sync_status->value,
                'sync_error' => $package->sync_error,
                'synced_at' => $package->synced_at?->diffForHumans(),
            ],
            'versions' => $package->versions->map(fn (PackageVersion $v) => [
                'version' => $v->version_pretty ?? $v->version,
                'released_at' => $v->released_at?->toDateString(),
                'reference' => $v->source_reference,
                'dependencies' => $deps->for($package->type, $v->metadata ?? []),
                'download_count' => $v->download_count,
                'dist_size' => $v->dist_size,
            ]),
            // Python distribution files (empty for other types).
            'pythonDists' => $dists->map(fn (PythonDist $d) => [
                'filename' => $d->filename,
                'version' => $d->version,
                'filetype' => $d->filetype,
                'size' => $d->size,
                'download_count' => $d->download_count,
                'uploaded_at' => $d->uploaded_at?->toDateString(),
            ]),
            'groups' => $package->groups->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug]),
            'stats' => $isPython ? [
                'downloads' => (int) $dists->sum('download_count'),
                'storage_bytes' => (int) $dists->sum('size'),
                'versions' => $dists->pluck('version')->unique()->count(),
            ] : [
                'downloads' => (int) $package->versions->sum('download_count'),
                'storage_bytes' => (int) $package->versions->sum('dist_size'),
                'versions' => $package->versions->count(),
            ],
            'activities' => ActivityPresenter::recentFor($package),
        ]);
    }

    public function probe(Request $request, RepositoryProbe $probe): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(PackageType::class)],
            'repository_url' => ['required', 'string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://'],
            'repository_token' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $probe->probe(PackageType::from($data['type']), $data['repository_url'], $data['repository_token'] ?? null);

        return response()->json($result);
    }

    public function store(StorePackageRequest $request): RedirectResponse|JsonResponse
    {
        // A package may only be attached to registries the user administers, so it can
        // never be slipped into another organization's registry.
        $groupIds = $request->validated('group_ids', []);
        foreach ($groupIds as $groupId) {
            $this->assertAdministersGroup(Group::findOrFail($groupId));
        }

        $package = Package::create($request->safe()->except('group_ids'));
        $package->groups()->sync($groupIds);

        // Publish-based packages (npm, Python) without a repository are filled by pushing
        // artifacts, so there is nothing to sync from git — skip the (doomed) sync job.
        if ($package->repository_url !== null) {
            SyncPackage::dispatch($package);
        }

        // The PackagePicker creates packages inline via fetch and needs the
        // created package back to add it directly to the selection.
        if ($request->expectsJson()) {
            return response()->json([
                'id' => $package->id,
                'name' => $package->name,
                'type' => $package->type,
            ], 201);
        }

        return back()->with('success', "Paket {$package->name} angelegt — Sync gestartet.");
    }

    public function destroy(Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        $package->delete();

        return back()->with('success', 'Paket gelöscht.');
    }
}
