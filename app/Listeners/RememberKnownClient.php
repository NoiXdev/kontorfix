<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\KnownClients;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Mark the browser on every completed authentication, whatever produced it.
 *
 * Hooked on the event rather than on the five controllers that call Auth::login(), so
 * that password login, the two-factor challenge, a passkey login, the OIDC callback, the
 * setup wizard and self-registration all mark the client without any of them having to
 * remember to. A path that authenticates a user and is *not* marked here would leave that
 * user's browser unrecognised and therefore refusable by the login throttle under load,
 * which is the failure mode this listener exists to prevent.
 *
 * API-key authentication calls Auth::setUser() and raises no Login event, so it never
 * reaches this listener — correct, since there is no browser to mark.
 *
 * Wired by the framework's listener discovery off the typed `handle` parameter; a single
 * event type is exactly the case discovery does handle, unlike DispatchOutgoingWebhooks
 * next door. `LoginAccountThrottleTest` asserts the cookie on a real login, so the wiring
 * is pinned by a test rather than by a registration line.
 */
class RememberKnownClient
{
    public function __construct(private readonly KnownClients $clients) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->clients->remember(app(Request::class), $event->user);
    }
}
