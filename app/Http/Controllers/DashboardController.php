<?php

namespace App\Http\Controllers;

use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use App\Models\Upstream;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // Customers belong in the portal, not on the operator dashboard.
        if ($request->user()->role === UserRole::Member) {
            return redirect()->route('portal.registries.index');
        }

        /** @var array<string,int> $byStatus */
        $byStatus = Package::query()
            ->selectRaw('sync_status, count(*) as c')
            ->groupBy('sync_status')
            ->pluck('c', 'sync_status')
            ->all();

        $failedJobs = 0;
        try {
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            // Table may be missing — fall back to 0.
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'packages' => Package::count(),
                'composer' => Package::where('type', PackageType::Composer)->count(),
                'npm' => Package::where('type', PackageType::Npm)->count(),
                'versions' => PackageVersion::count(),
                'groups' => Group::count(),
                'tokens' => RegistryToken::count(),
                'domains' => Domain::count(),
                'upstreams' => Upstream::count(),
                'failedJobs' => $failedJobs,
                'sync' => [
                    'synced' => (int) ($byStatus[SyncStatus::Synced->value] ?? 0),
                    'syncing' => (int) ($byStatus[SyncStatus::Syncing->value] ?? 0),
                    'pending' => (int) ($byStatus[SyncStatus::Pending->value] ?? 0),
                    'failed' => (int) ($byStatus[SyncStatus::Failed->value] ?? 0),
                ],
            ],
            'recent' => Package::query()
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
        ]);
    }
}
