<?php

namespace App\Providers;

use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Listeners\DispatchOutgoingWebhooks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ein Listener bedient zwei Event-Typen — Auto-Discovery matcht anhand des
        // typisierten `handle`-Parameters und würde daher nicht zuverlässig beide
        // Methoden verdrahten. Deshalb explizite Registrierung statt Discovery.
        Event::listen(PackageSynced::class, [DispatchOutgoingWebhooks::class, 'onSynced']);
        Event::listen(PackageSyncFailed::class, [DispatchOutgoingWebhooks::class, 'onFailed']);
    }
}
