<?php

namespace App\Console\Commands;

use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Console\Command;

class ResyncPackages extends Command
{
    protected $signature = 'packages:resync';

    protected $description = 'Reihte einen Sync-Job für jedes VCS-basierte Paket ein.';

    public function handle(): int
    {
        $count = 0;
        Package::query()->whereNotNull('repository_url')->each(function (Package $package) use (&$count) {
            SyncPackage::dispatch($package);
            $count++;
        });

        $this->info("{$count} Paket(e) zum Re-Sync eingereiht.");

        return self::SUCCESS;
    }
}
