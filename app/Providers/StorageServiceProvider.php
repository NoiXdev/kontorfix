<?php

namespace App\Providers;

use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class StorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('storage_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        config(['filesystems.disks.artifacts' => app(StorageManager::class)->diskConfig()]);
    }
}
