<?php

namespace App\Http\Middleware;

use App\Services\Http\TrustedHosts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides, per request, what every absolute URL this application generates is rooted at.
 *
 * Two properties have to hold at once, and a single `URL::forceRootUrl(APP_URL)` at boot
 * only delivered the first:
 *
 *  1. A generated link must never follow a `Host` an attacker chose. The password-reset
 *     notification renders synchronously inside the request that asked for it, so
 *     `Host: attacker.example.net` on POST /forgot-password otherwise produced a reset
 *     link on the attacker's domain — in the *victim's* mailbox.
 *  2. A hostname the operator genuinely attached to a registry must keep working. Pinning
 *     the root to APP_URL made every script, stylesheet, Inertia XHR and form action on
 *     such a hostname cross-origin: a 419 without CSP, and hard-blocked under the
 *     documented `SECURITY_CSP=enforce` policy, whose `'self'` is the tenant origin.
 *
 * The `domains` table is the authority for (2): it is the list of hostnames this instance
 * genuinely serves, it is the same list `TrustHosts` already accepts at the edge, and only
 * the instance super-admin may write it (`POST /admin/domains` and
 * `POST /api/v1/groups/{group}/domains` are both in the `super` group). A row therefore
 * cannot widen the set of hosts a link may be rooted at beyond what the edge already
 * admits — and the row itself contributes only a bare, LDH-validated hostname; the scheme
 * and port still come from the request, i.e. from the trusted proxy.
 *
 * Anything else — including the APP_URL host itself and the loopback names, which need no
 * request-relative URLs — is pinned to APP_URL, exactly as before.
 *
 * Global middleware on purpose: this must be settled before routing, since a redirect
 * emitted by `auth` or by a route-model-binding miss is already an absolute URL.
 */
class PinUrlRoot
{
    public function handle(Request $request, Closure $next): Response
    {
        URL::forceRootUrl($this->rootFor($request));

        return $next($request);
    }

    /**
     * Null means "generate from this request", which is only ever returned for a host
     * this instance is configured to serve.
     */
    private function rootFor(Request $request): ?string
    {
        if (TrustedHosts::isAttachedHostname($request->getHost())) {
            return null;
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        // No configured root to pin to. Leaving the generator on the request root is the
        // pre-existing behaviour for this case; TrustedHosts documents the same fail-open
        // and the health page reports it.
        return $appUrl !== '' ? $appUrl : null;
    }
}
