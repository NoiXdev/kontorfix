<?php

namespace App\Console\Commands;

use App\Enums\PackageSourceMode;
use App\Jobs\SyncMirrorPackage;
use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Console\Command;

class ResyncPackages extends Command
{
    protected $signature = 'packages:resync';

    protected $description = 'Reihte einen Sync-Job für jedes git- oder mirror-basierte Paket ein.';

    public function handle(): int
    {
        $count = 0;

        // A mirror-sourced package carries no repository_url at all (it has no git
        // repository to reference), so the old `whereNotNull('repository_url')` scope alone
        // would silently skip every one of them. Widened to also catch source_mode=mirror,
        // then dispatched per-row below by mode — a publish-based package (npm, Python) may
        // still carry a repository_url purely for reference (publishing sends the artifact,
        // not the tree), so it is excluded the same way isGitSourced() already excluded it.
        Package::query()
            ->where(fn ($q) => $q->whereNotNull('repository_url')->orWhere('source_mode', PackageSourceMode::Mirror->value))
            ->each(function (Package $package) use (&$count) {
                if ($package->isGitSourced()) {
                    SyncPackage::dispatch($package);
                    $count++;
                } elseif ($package->isMirrorSourced()) {
                    SyncMirrorPackage::dispatch($package);
                    $count++;
                }
            });

        $this->info("{$count} Paket(e) zum Re-Sync eingereiht.");

        return self::SUCCESS;
    }
}
