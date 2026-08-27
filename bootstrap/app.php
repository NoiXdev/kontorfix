<?php

use App\Exceptions\UpstreamException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateRegistry;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureRegistryTypeEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PinUrlRoot;
use App\Http\Middleware\RejectRobotWebSession;
use App\Http\Middleware\RequireSetup;
use App\Http\Middleware\ResolveRegistryContext;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Http\TrustedHosts;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        // No `health:` here on purpose. Laravel's built-in /up renders a vendor Blade
        // that pulls an unpinned @tailwindcss/browser build from jsDelivr (no SRI) plus
        // a bunny.net stylesheet — third-party script executing on our own origin, where
        // the XSRF-TOKEN cookie is readable. The Blade cannot be overridden (View::file()
        // with an absolute path), so we register our own below.
        then: function () {
            // Container healthcheck (`curl -fsS http://localhost:8080/up`). Registered
            // statelessly like the routes below, and *before* them: registry.php ends in
            // a root-level npm packument catch-all that would otherwise swallow /up on a
            // custom-domain host. The status code is the whole contract — the deeper
            // dependency checks live behind auth in the status endpoints.
            Route::get('/up', fn () => response()->json(['status' => 'ok']))->name('health');

            // Registry routes are stateless (Composer client sends no cookies/CSRF) —
            // deliberately outside the `web` group, protected only by `registry.auth`.
            Route::group([], base_path('routes/registry.php'));

            // Incoming webhooks are likewise stateless (external git hosts send
            // no cookies/CSRF token) — secured exclusively via signature verification.
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Refuse a `Host` this instance does not answer to. Without an allowlist,
        // getSchemeAndHttpHost() echoes whatever the client sent, and a proxy with a
        // catch-all router — the natural shape once custom registry domains are in use —
        // lets an anonymous caller put its own domain into the password-reset link that
        // is then delivered to the victim. Complements, and does not replace, PinUrlRoot
        // below: this one also covers the consumers that build URLs from the request
        // rather than from the URL generator.
        // `subdomains: false` because TrustedHosts::patterns() already emits the
        // subdomain pattern for the APP_URL host itself and would otherwise duplicate it.
        $middleware->trustHosts(at: fn (): array => TrustedHosts::patterns(), subdomains: false);

        // The framework class installs the allowlist; this subclass adds the one exemption the
        // health endpoint needs. `trustHosts()` above still configures it — TrustHosts::at()
        // writes a static declared on the parent, which the subclass shares.
        $middleware->replace(TrustHosts::class, App\Http\Middleware\TrustHosts::class);

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

        // Decides what generated absolute URLs are rooted at, for this request only. Must
        // run after trustProxies() (it reads the forwarded host) and is deliberately
        // global: a redirect emitted by `auth`, or by the router itself, is already an
        // absolute URL, so the decision cannot wait for the route middleware groups.
        $middleware->append(PinUrlRoot::class);

        // Global on purpose: the registry, webhook, API and health routes deliberately
        // live outside the `web` group and would otherwise get no security headers at
        // all. The middleware itself decides which headers suit which response.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            // Runs across the whole web group on purpose — while no user exists, the
            // wizard is the only reachable route. Anything narrower (e.g. only the
            // GET pages) would leave POST /register open, which would both hand the
            // instance to whoever posts first and permanently seal the wizard.
            RequireSetup::class,
            // Pins every web session to the password hash it was created under. Without
            // it, changing or resetting the password evicts nobody: a stolen session
            // cookie keeps working forever (SESSION_LIFETIME slides on every request).
            // Laravel sorts it into the priority list via the AuthenticatesSessions
            // contract, so it lands after StartSession and Authenticate. Sessions that
            // predate it get the hash stored on their next request and are unaffected.
            AuthenticateSession::class,
            HandleInertiaRequests::class,
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
