<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');

        if (config('security.hsts') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP can easily break Inertia/Vite — hence report-only and optional for now.
        if (config('security.csp_report_only')) {
            $response->headers->set(
                'Content-Security-Policy-Report-Only',
                "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
            );
        }

        return $response;
    }
}
