<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Listeners\DispatchOutgoingWebhooks;
use App\Models\User;
use App\Services\Broadcasting\ReverbConfigGuard;
use App\Services\Upstream\HostResolver;
use App\Services\Upstream\SystemHostResolver;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The one DNS lookup the outbound address policy rests on (UrlSafety consumes it
        // for every upstream fetch, git transport, webhook delivery and OIDC discovery
        // document). Bound rather than hard-coded so tests can substitute a deterministic
        // resolver through the container instead of a public static setter that
        // production code could also reach.
        $this->app->singleton(HostResolver::class, SystemHostResolver::class);

        // The interactive API browser is a vendor view that loads Stoplight Elements from
        // unpkg.com and runs it on this origin, un-SRI'd, with a `credentials: include`
        // fetch wrapper already built in — and every visitor is by definition an admin of
        // the operator organization. Whoever controls that delivery therefore executes
        // script in the highest-privilege session on the instance.
        //
        // Scramble registers `docs/api` and `docs/api.json` unconditionally, so until now
        // there was no way to decline that dependency at all. The default is unchanged
        // (the browser stays on); an operator who does not need it can now switch it off
        // and keep only the machine-readable spec they generate themselves. This must run
        // in register(): ScrambleServiceProvider reads the flag in its own boot().
        if (! (bool) config('kontorfix.api_docs_enabled', true)) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pin every generated absolute URL to APP_URL instead of to the request's `Host`.
        //
        // The password-reset notification renders synchronously inside the request that
        // asked for it, so without this a `Host: attacker.example.net` on
        // POST /forgot-password produced a reset link on the attacker's domain — in the
        // *victim's* mailbox. Same mechanism for the signed verification link and the
        // invitation mail. trustHosts() in bootstrap/app.php refuses such a request in
        // the first place; this is the half that does not depend on the allowlist being
        // resolvable, and it is why an unset APP_URL can safely disable that allowlist.
        $appUrl = (string) config('app.url');
        if (parse_url($appUrl, PHP_URL_HOST) !== null) {
            URL::forceRootUrl($appUrl);
        }

        // One listener serves two event types — auto-discovery matches based on the
        // typed `handle` parameter and would therefore not reliably wire up both
        // methods. Hence explicit registration instead of discovery.
        Event::listen(PackageSynced::class, [DispatchOutgoingWebhooks::class, 'onSynced']);
        Event::listen(PackageSyncFailed::class, [DispatchOutgoingWebhooks::class, 'onFailed']);

        // The Pusher protocol authorizes private channels inside the websocket server,
        // against the app secret — routes/channels.php never sees a raw wss:// client.
        // So `reverb:start` must not come up on a secret this repository published.
        // Hooked at the command rather than at application boot on purpose: the exposure
        // is the running websocket server, and a broadcasting misconfiguration should
        // not take the registry offline. See ReverbConfigGuard.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (str_starts_with((string) $event->command, 'reverb:')) {
                ReverbConfigGuard::assertUsable();
            }
        });

        // Coarse IP limit protects the unauthenticated API surface (bad-bearer floods →
        // DB lookup). The fine-grained per-key limiter additionally runs in AuthenticateApiKey.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(240)->by($request->ip());
        });

        // Requesting a reset link. Unauthenticated and expensive: Laravel runs the token
        // lookup inside a 200ms Timebox, so an unlimited endpoint pins every PHP worker
        // for the price of a curl loop, and it sends mail, so it also mail-bombs any
        // known address. Two counters, because they stop different attacks:
        //
        //  - per source address, for the worker-exhaustion angle. This is the real peer:
        //    Traefik overwrites X-Forwarded-For and Symfony's trusted-proxy walk strips the
        //    rest, so the attacker cannot rotate it with a header.
        //  - per target account, so an attacker with a pool of addresses still cannot flood
        //    one inbox — the same account-scoped model the 2FA challenge already uses.
        //
        // Six per minute matches the throttle already on the 2FA challenge and email
        // verification; ten per hour and address is far above what a human ever needs
        // (a user who did not receive the mail retries two or three times) while keeping
        // the counter per-account rather than global, so one target cannot starve another.
        RateLimiter::for('password-reset', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(6)->by('password-reset-ip:'.$request->ip()),
                Limit::perHour(10)->by('password-reset-account:'.$email),
            ];
        });

        // Completing a reset is a separate limiter on purpose. It is a different action
        // with a different abuse profile: the requester must present a 64-character
        // single-use token, so there is nothing per-account left to brute force here,
        // and the account-scoped send bucket above is burnable by anyone who knows an
        // address. Sharing one bucket therefore meant ten POSTs to /forgot-password
        // locked the victim out of /reset-password for the whole 60-minute token
        // lifetime — the recovery path denied to the only person holding a valid token.
        // What remains worth limiting is the same worker-exhaustion angle, which is a
        // property of the source address, not of the target account.
        RateLimiter::for('password-reset-complete', function (Request $request): Limit {
            return Limit::perMinute(6)->by('password-reset-complete-ip:'.$request->ip());
        });

        // Deliberate security decision (v0.8): a passkey here strictly requires
        // user verification (biometrics/PIN, see config/passkeys.php) and is thus itself
        // a phishing-resistant multi-factor proof (possession + verification). A
        // passkey login therefore replaces the TOTP step and is ALSO allowed with 2FA
        // active — consistent with the FIDO2 standard. This policy is deliberately
        // registered explicitly (instead of relying on the "allow" default), so that
        // skipping the TOTP step is a visible, tested decision. To enforce 2FA instead
        // (block passkey when 2FA is active): `return ! $user->hasConfirmedTwoFactor();`
        //
        // Additional inline check: robot accounts must never be able to log in
        // interactively — not even via passkey. The RejectRobotWebSession middleware
        // catches this anyway (defense in depth), but only kicks in after the login (1
        // response window). The check here prevents the login from happening at all.
        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $user, Passkey $passkey): bool {
            if ($user instanceof User && $user->isRobot()) {
                return false;
            }

            return true;
        });

        // OpenAPI docs (Scramble) document exclusively the versioned management
        // endpoints under api/v1 — the Composer/npm/proxy routes are protocol
        // endpoints for package clients and don't belong in the REST reference.
        Scramble::configure()
            ->routes(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1'));

        // A super-admin may do everything — short-circuit every policy/gate check.
        // Returning null (not false) for everyone else lets the individual checks run.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Access gate for the docs routes (/docs/api, /docs/api.json). Scramble's
        // RestrictedDocsAccess middleware evaluates this gate in all environments except
        // `local`. Only admins of an operator organization may view the API reference —
        // it exposes the internal management API.
        Gate::define('viewApiDocs', function (User $user): bool {
            return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
        });
    }
}
