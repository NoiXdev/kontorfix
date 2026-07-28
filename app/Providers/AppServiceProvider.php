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
        // Ein Listener bedient zwei Event-Typen — Auto-Discovery matcht anhand des
        // typisierten `handle`-Parameters und würde daher nicht zuverlässig beide
        // Methoden verdrahten. Deshalb explizite Registrierung statt Discovery.
        Event::listen(PackageSynced::class, [DispatchOutgoingWebhooks::class, 'onSynced']);
        Event::listen(PackageSyncFailed::class, [DispatchOutgoingWebhooks::class, 'onFailed']);

        // Coarse IP-Limit schützt die unauthentifizierte API-Fläche (bad-Bearer-Fluten →
        // DB-Lookup). Der feine Pro-Key-Limiter läuft zusätzlich in AuthenticateApiKey.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(240)->by($request->ip());
        });

        // Bewusste Sicherheitsentscheidung (v0.8): Ein Passkey verlangt hier zwingend
        // User-Verification (Biometrie/PIN, siehe config/passkeys.php) und ist damit selbst
        // ein phishing-resistenter Mehr-Faktor-Nachweis (Besitz + Verifikation). Ein
        // Passkey-Login ersetzt daher den TOTP-Schritt und ist AUCH bei aktiver 2FA erlaubt —
        // konsistent mit dem FIDO2-Standard. Diese Policy ist absichtlich explizit registriert
        // (statt sich auf den „allow"-Default zu verlassen), damit der Verzicht auf den
        // TOTP-Schritt eine sichtbare, getestete Entscheidung ist. Um 2FA stattdessen zu
        // erzwingen (Passkey bei aktiver 2FA blocken): `return ! $user->hasConfirmedTwoFactor();`
        //
        // Zusätzlicher Inline-Check: Robot-Accounts dürfen sich grundsätzlich nicht
        // interaktiv anmelden — auch nicht per Passkey. Die RejectRobotWebSession-Middleware
        // fängt das ohnehin ab (Defense-in-Depth), greift aber erst nach dem Login (1-Response-
        // Fenster). Der Check hier verhindert den Login von vornherein.
        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $user, Passkey $passkey): bool {
            if ($user instanceof User && $user->isRobot()) {
                return false;
            }

            return true;
        });

        // OpenAPI-Doku (Scramble) dokumentiert ausschließlich die versionierten
        // Management-Endpunkte unter api/v1 — die Composer-/npm-/Proxy-Routen sind
        // Protokoll-Endpunkte für Package-Clients und gehören nicht in die REST-Referenz.
        Scramble::configure()
            ->routes(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1'));

        // Zugriffs-Gate für die Doku-Routen (/docs/api, /docs/api.json). Scrambles
        // RestrictedDocsAccess-Middleware wertet dieses Gate in allen Umgebungen außer
        // `local` aus. Nur Admins einer Betreiber-Organisation dürfen die API-Referenz
        // sehen — sie legt die interne Verwaltungs-API offen.
        Gate::define('viewApiDocs', function (User $user): bool {
            return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
        });
    }
}
