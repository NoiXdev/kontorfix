<?php

use App\Exceptions\UpstreamException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateRegistry;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureRegistryTypeEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectRobotWebSession;
use App\Http\Middleware\RequireSetup;
use App\Http\Middleware\ResolveRegistryContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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
            // Registry routes are stateless (Composer client sends no cookies/CSRF) —
            // deliberately outside the `web` group, protected only by `registry.auth`.
            Route::group([], base_path('routes/registry.php'));

            // Incoming webhooks are likewise stateless (external git hosts send
            // no cookies/CSRF token) — secured exclusively via signature verification.
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Deployment runs behind a reverse proxy (Traefik/Portainer). Without this,
        // getSchemeAndHttpHost() would return the internal host and the generated
        // dist URLs in the Composer metadata would be wrong. The trusted
        // proxy IPs/networks are configurable via TRUSTED_PROXIES (default: private
        // network ranges + localhost), '*' remains available as an explicit opt-out.
        $proxies = (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1');
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            // Runs across the whole web group on purpose — while no user exists, the
            // wizard is the only reachable route. Anything narrower (e.g. only the
            // GET pages) would leave POST /register open, which would both hand the
            // instance to whoever posts first and permanently seal the wizard.
            RequireSetup::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            RejectRobotWebSession::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Without this, priority sorting pulls `auth` ahead of RequireSetup, so an
        // auth-protected route on a fresh instance would bounce via /login before
        // reaching the wizard. Deciding "is this instance set up at all?" belongs
        // before "is this visitor logged in?".
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RequireSetup::class,
        );

        $middleware->alias([
            'registry.auth' => AuthenticateRegistry::class,
            'registry.context' => ResolveRegistryContext::class,
            'registry.type' => EnsureRegistryTypeEnabled::class,
            'operator' => EnsureOperator::class,
            'super' => EnsureSuperAdmin::class,
            'api.auth' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A broken/slow upstream is a gateway error, not a 500 on our part.
        // UpstreamException is thrown exclusively in the registry proxy — hence always
        // 502, regardless of whether access came via /r/{slug} or a custom domain.
        $exceptions->render(fn (UpstreamException $e, Request $request) => response()->json(
            ['error' => 'Upstream registry unavailable.'], 502
        ));
    })->create();
