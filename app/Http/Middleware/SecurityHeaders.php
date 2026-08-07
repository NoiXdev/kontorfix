<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered globally, so the stateless registry, webhook, API and health routes get
 * headers too — they sit outside the `web` group by design and used to get none.
 *
 * The set is deliberately split. `X-Content-Type-Options`, `Referrer-Policy` and HSTS
 * describe the transport and the payload's type; they mean the same thing on a package
 * tarball as on a page. `X-Frame-Options`, `Permissions-Policy` and the CSP only govern
 * how a *browser document* behaves — on a JSON body or a `.tgz` they are inert, and
 * shipping a policy tailored to the Inertia/Vite bundle with a package download would be
 * cargo cult. Those three are therefore keyed on the response actually being HTML, not on
 * the route group, which also covers the PEP 503 index served from the registry routes.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = $this->cspMode();

        // Must exist before the view renders: `@vite` and `@routes` stamp it onto the
        // tags that the policy below then has to allow.
        $nonce = $mode === 'off' ? null : Vite::useCspNonce();

        $response = $next($request);

        // Universal — every response, whatever the content type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (config('security.hsts') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $this->isHtmlDocument($response)) {
            return $response;
        }

        // Document-only from here on.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');

        if ($mode === 'report') {
            $response->headers->set('Content-Security-Policy-Report-Only', $this->policy($nonce));
        } elseif ($mode === 'enforce') {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    private function cspMode(): string
    {
        $mode = (string) config('security.csp', 'off');

        return in_array($mode, ['report', 'enforce'], true) ? $mode : 'off';
    }

    private function isHtmlDocument(Response $response): bool
    {
        return str_contains(
            strtolower((string) $response->headers->get('Content-Type', '')),
            'text/html',
        );
    }

    /**
     * Kept in sync with what `resources/views/app.blade.php` actually loads — a policy
     * that blanks the SPA the moment it is enforced would be worse than none at all.
     */
    private function policy(?string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            // The layout emits exactly one inline script (Ziggy's `@routes`), and it
            // carries this nonce. Everything else is a same-origin Vite bundle.
            "script-src 'self'".($nonce !== null ? " 'nonce-{$nonce}'" : ''),
            // Inertia's progress bar appends a <style> element and Vue writes style
            // attributes; neither can carry a nonce. fonts.bunny.net serves the webfont
            // stylesheet the layout links.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data: blob:",
            // Inertia XHR plus the Reverb websocket, which may run on its own host/port.
            "connect-src 'self' ws: wss:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
