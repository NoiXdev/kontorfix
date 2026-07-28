<?php

use App\Exceptions\UpstreamException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateRegistry;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectRobotWebSession;
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
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Registry-Routen sind stateless (Composer-Client schickt keine Cookies/CSRF) —
            // bewusst außerhalb der `web`-Gruppe, nur mit `registry.auth` geschützt.
            Route::group([], base_path('routes/registry.php'));

            // Incoming-Webhooks sind ebenfalls stateless (externe Git-Hoster schicken
            // keine Cookies/CSRF-Token) — Absicherung ausschließlich über Signaturprüfung.
            Route::group([], base_path('routes/webhooks.php'));
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
            RejectRobotWebSession::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'registry.auth' => AuthenticateRegistry::class,
            'registry.context' => ResolveRegistryContext::class,
            'operator' => EnsureOperator::class,
            'role' => EnsureUserRole::class,
            'api.auth' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Ein defekter/langsamer Upstream ist ein Gateway-Fehler, kein 500 unsererseits.
        // UpstreamException wird ausschließlich im Registry-Proxy geworfen — daher immer
        // 502, unabhängig davon, ob der Zugriff über /r/{slug} oder eine Custom-Domain kam.
        $exceptions->render(fn (UpstreamException $e, Request $request) => response()->json(
            ['error' => 'Upstream registry unavailable.'], 502
        ));
    })->create();
