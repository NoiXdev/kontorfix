<?php

namespace App\Providers;

use App\Services\Mail\MailManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Mirrors StorageServiceProvider: the table is absent during the very first
        // `migrate` run (and while artisan boots against an empty DB), so a missing
        // table must not be fatal — the .env config stays in effect until then.
        try {
            if (! Schema::hasTable('mail_settings')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        app(MailManager::class)->apply();
    }
}
