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

        if ($mode !== 'off') {
            $collector = $this->reportCollector();
            if ($collector !== null) {
                $response->headers->set('Reporting-Endpoints', 'csp-endpoint="'.$collector.'"');
            }

            $response->headers->set(
                $mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
                $this->policy($nonce, $request, $collector),
            );
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
     *
     * Two documents this application renders but does not author need their own
     * `script-src`, see vendorScriptSources(). Everything that does not depend on a
     * page's own scripts — frame-ancestors, object-src, base-uri, form-action — is
     * identical everywhere, because those are the directives worth having on a
     * dashboard that can retry jobs and an API browser that can call the API.
     */
    private function policy(?string $nonce, Request $request, ?string $collector): string
    {
        $vendor = $this->vendorScriptSources($request);

        $directives = [
            "default-src 'self'",
            // The layout emits exactly one inline script (Ziggy's `@routes`), and it
            // carries this nonce. Everything else is a same-origin Vite bundle.
            $vendor['script-src'] ?? "script-src 'self'".($nonce !== null ? " 'nonce-{$nonce}'" : ''),
            // Inertia's progress bar appends a <style> element and Vue writes style
            // attributes; neither can carry a nonce. fonts.bunny.net serves the webfont
            // stylesheet the layout links.
            $vendor['style-src'] ?? "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data: blob:",
            // Inertia XHR plus the Reverb websocket, which may run on its own host/port.
            "connect-src 'self' ws: wss:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        if ($collector !== null) {
            // `report-uri` is deprecated but still the only one Safari and older
            // Chromium honour; `report-to` needs the Reporting-Endpoints header set
            // alongside it. Without either, "report mode" only writes to the visitor's
            // own console and the operator never sees a violation.
            $directives[] = 'report-uri '.$collector;
            $directives[] = 'report-to csp-endpoint';
        }

        return implode('; ', $directives);
    }

    /**
     * Horizon inlines its entire dashboard as one un-nonced `<script type="module">`,
     * and Scramble's API browser loads Stoplight Elements from unpkg plus its own inline
     * bootstrap. Neither view is ours to nonce, so a nonce-only `script-src` blanks both
     * the moment the documented `SECURITY_CSP=enforce` is set. They get `'unsafe-inline'`
     * *without* a nonce — a nonce would make browsers ignore `'unsafe-inline'` and
     * re-break the page — and keep every other directive.
     *
     * Matched on route name rather than path, so `HORIZON_PATH` cannot silently move a
     * page out of its own exemption.
     *
     * @return array<string, string>
     */
    private function vendorScriptSources(Request $request): array
    {
        $name = (string) $request->route()?->getName();

        if (str_starts_with($name, 'horizon.')) {
            return ['script-src' => "script-src 'self' 'unsafe-inline'"];
        }

        if ($name === 'scramble.docs.ui') {
            return [
                'script-src' => "script-src 'self' 'unsafe-inline' https://unpkg.com",
                'style-src' => "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://unpkg.com",
            ];
        }

        return [];
    }

    /** Where violation reports go; null means "nowhere but the visitor's console". */
    private function reportCollector(): ?string
    {
        $uri = trim((string) config('security.csp_report_uri', ''));

        return $uri !== '' ? $uri : null;
    }
}
