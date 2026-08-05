<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Listeners\DispatchOutgoingWebhooks;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One listener serves two event types — auto-discovery matches based on the
        // typed `handle` parameter and would therefore not reliably wire up both
        // methods. Hence explicit registration instead of discovery.
        Event::listen(PackageSynced::class, [DispatchOutgoingWebhooks::class, 'onSynced']);
        Event::listen(PackageSyncFailed::class, [DispatchOutgoingWebhooks::class, 'onFailed']);

        // Coarse IP limit protects the unauthenticated API surface (bad-bearer floods →
        // DB lookup). The fine-grained per-key limiter additionally runs in AuthenticateApiKey.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(240)->by($request->ip());
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
