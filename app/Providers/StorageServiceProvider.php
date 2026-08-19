<?php

namespace App\Providers;

use App\Services\Storage\StorageManager;
use Illuminate\Support\ServiceProvider;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared instance so "have the persisted settings been applied yet?" is a
        // property of the container rather than of whichever copy a caller happens to
        // hold — see StorageManager::applyPersisted().
        $this->app->singleton(StorageManager::class);
    }

    public function boot(): void
    {
        // Reading the setting here used to mean one query — and, against a database
        // whose storage_settings table is still empty, one INSERT — in *every*
        // application boot. Under test that is a fresh connection per test (measured:
        // ~1000 per suite run) issued before RefreshDatabase opens its transaction, so
        // the insert commits and outlives the test that caused it.
        //
        // Nothing about a request that never stores an artifact needs the setting, so
        // it is applied the first time anything reaches for the filesystem instead.
        $this->app->resolving('filesystem', function ($filesystem, $app): void {
            $app->make(StorageManager::class)->applyPersisted();
        });
    }
}
