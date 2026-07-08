<?php

use App\Exceptions\UpstreamException;
use App\Http\Middleware\AuthenticateRegistry;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveRegistryContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Registry-Routen sind stateless (Composer-Client schickt keine Cookies/CSRF) —
            // bewusst außerhalb der `web`-Gruppe, nur mit `registry.auth` geschützt.
            Route::group([], base_path('routes/registry.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Deployment läuft hinter einem Reverse Proxy (Traefik/Portainer). Ohne dies
        // liefert getSchemeAndHttpHost() den internen Host und die generierten
        // Dist-URLs in den Composer-Metadaten wären falsch.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'registry.auth' => AuthenticateRegistry::class,
            'registry.context' => ResolveRegistryContext::class,
            'role' => EnsureUserRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Ein defekter/langsamer Upstream ist ein Gateway-Fehler, kein 500 unsererseits.
        $exceptions->render(fn (UpstreamException $e, Request $request) => $request->expectsJson() || $request->is('r/*')
            ? response()->json(['error' => 'Upstream registry unavailable.'], 502)
            : null);
    })->create();
