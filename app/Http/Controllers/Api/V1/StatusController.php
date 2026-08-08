<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SyncStatus;
use App\Http\Controllers\Concerns\ScopesApiToUser;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\Health\HealthService;
use App\Support\CredentialUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatusController extends Controller
{
    use ScopesApiToUser;

    /**
     * Instance health at a glance (super-admin only): are the core dependencies up, how
     * many jobs failed, and the instance-wide package sync totals. Built for uptime
     * monitors — `healthy` is the single boolean to alert on.
     */
    public function show(HealthService $health): JsonResponse
    {
        $checks = $health->checks();

        return response()->json(['data' => [
            'healthy' => collect($checks)->every(fn (array $c): bool => $c['ok']),
            'checks' => $checks,
            'failed_jobs' => $this->failedJobs(),
            'packages' => Package::count(),
            'sync' => $this->syncCounts(Package::query()->pluck('id')),
        ]]);
    }

    /**
     * Package sync health for the caller's own organizations — "which of my packages
     * failed?" at a glance. Scoped, so any member can call it for their registries;
     * a super-admin sees every package.
     */
    public function packages(): JsonResponse
    {
        $ids = $this->scopePackageRead(Package::query())->pluck('id');

        $failed = Package::whereKey($ids)
            ->where('sync_status', SyncStatus::Failed->value)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'type', 'sync_status', 'sync_error', 'repository_url', 'synced_at'])
            ->map(fn (Package $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type->value,
                'error' => $p->sync_error,
                'repository_url' => CredentialUrl::redact($p->repository_url),
                'synced_at' => $p->synced_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [
            'total' => $ids->count(),
            'sync' => $this->syncCounts($ids),
            'failed' => $failed,
        ]]);
    }

    /**
     * @param  Collection<int, string>  $packageIds
     * @return array{synced:int, syncing:int, pending:int, failed:int}
     */
    private function syncCounts(Collection $packageIds): array
    {
        /** @var array<string,int> $byStatus */
        $byStatus = Package::whereKey($packageIds)
            ->selectRaw('sync_status, count(*) as c')
            ->groupBy('sync_status')
            ->pluck('c', 'sync_status')
            ->all();

        return [
            'synced' => (int) ($byStatus[SyncStatus::Synced->value] ?? 0),
            'syncing' => (int) ($byStatus[SyncStatus::Syncing->value] ?? 0),
            'pending' => (int) ($byStatus[SyncStatus::Pending->value] ?? 0),
            'failed' => (int) ($byStatus[SyncStatus::Failed->value] ?? 0),
        ];
    }

    private function failedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            // The table may be absent on a fresh instance — treat as zero.
            return 0;
        }
    }
}
