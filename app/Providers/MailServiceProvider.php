<?php

namespace App\Providers;

use App\Services\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Mirrors StorageServiceProvider: the "already applied" flag has to be shared.
        $this->app->singleton(MailManager::class);
    }

    public function boot(): void
    {
        // Same reasoning as StorageServiceProvider: applying the persisted settings at
        // boot put a database round-trip into every application boot, and it silently
        // overrode the environment-configured mailer before anyone asked to send
        // anything. Laravel resolves `mail.manager` on the first send (the `mailer`
        // binding goes through it too), which is exactly when the settings matter.
        $this->app->resolving('mail.manager', function ($manager, $app): void {
            $app->make(MailManager::class)->applyPersisted();
        });
    }
}
