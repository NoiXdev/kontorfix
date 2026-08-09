<?php

namespace App\Http\Controllers;

use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use App\Models\Upstream;
use App\Services\Scope\OrgScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(Request $request): Response|RedirectResponse
    {
        // Anyone without console access (plain members) belongs in the portal.
        if (! $request->user()->canAdministerConsole()) {
            return redirect()->route('portal.registries.index');
        }

        $scope = app(OrgScope::class);
        $orgIds = $this->scopedOrgIds();
        // Every stat is scoped to the organizations in the active scope, so an org admin
        // only ever sees their own numbers. The registries in scope drive the package,
        // version, domain and upstream rollups below.
        $groupIds = $this->scopeGroupQuery(Group::query())->pluck('id');
        $packageIds = $this->scopePackageQuery(Package::query())->pluck('id');

        /** @var array<string,int> $byStatus */
        $byStatus = Package::query()
            ->whereKey($packageIds)
            ->selectRaw('sync_status, count(*) as c')
            ->groupBy('sync_status')
            ->pluck('c', 'sync_status')
            ->all();

        // The failed-jobs queue is instance-wide infrastructure, not per-organization —
        // only the super-admin sees it.
        $failedJobs = 0;
        if ($scope->spansAllOrganizations()) {
            try {
                $failedJobs = DB::table('failed_jobs')->count();
            } catch (\Throwable) {
                // Table may be missing — fall back to 0.
            }
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'packages' => $packageIds->count(),
                'composer' => Package::whereKey($packageIds)->where('type', PackageType::Composer)->count(),
                'npm' => Package::whereKey($packageIds)->where('type', PackageType::Npm)->count(),
                'versions' => PackageVersion::whereIn('package_id', $packageIds)->count(),
                'groups' => $groupIds->count(),
                'tokens' => RegistryToken::notRevoked()->whereIn('organization_id', $orgIds)->count(),
                'domains' => Domain::whereIn('group_id', $groupIds)->count(),
                'upstreams' => Upstream::whereIn('group_id', $groupIds)->count(),
                'failedJobs' => $failedJobs,
                'sync' => [
                    'synced' => (int) ($byStatus[SyncStatus::Synced->value] ?? 0),
                    'syncing' => (int) ($byStatus[SyncStatus::Syncing->value] ?? 0),
                    'pending' => (int) ($byStatus[SyncStatus::Pending->value] ?? 0),
                    'failed' => (int) ($byStatus[SyncStatus::Failed->value] ?? 0),
                ],
            ],
            'recent' => Package::query()
                ->whereKey($packageIds)
                ->whereNotNull('synced_at')
                ->latest('synced_at')
                ->limit(6)
                ->get(['name', 'type', 'sync_status', 'synced_at'])
                ->map(fn (Package $p) => [
                    'name' => $p->name,
                    'type' => $p->type->value,
                    'status' => $p->sync_status->value,
                    'synced_at' => $p->synced_at?->diffForHumans(),
                ]),
            // The failed-packages widget: the most recently broken syncs in scope, with
            // the error so the cause is visible without opening each package.
            'failedPackages' => Package::query()
                ->whereKey($packageIds)
                ->where('sync_status', SyncStatus::Failed->value)
                ->latest('synced_at')
                ->limit(6)
                ->get(['id', 'name', 'type', 'sync_error', 'synced_at'])
                ->map(fn (Package $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type->value,
                    'error' => $p->sync_error,
                    'synced_at' => $p->synced_at?->diffForHumans(),
                ]),
        ]);
    }
}
