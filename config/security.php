<?php

return [
    // Only enable HSTS if TLS is terminated (reverse proxy). Default off.
    'hsts' => env('SECURITY_HSTS', false),

    // Content-Security-Policy mode for HTML documents: 'off' | 'report' | 'enforce'.
    // The documented rollout is 'report' first (collect violations), then 'enforce'.
    // SECURITY_CSP_REPORT_ONLY is the older switch and still selects 'report', so an
    // existing deployment does not silently lose its policy on upgrade.
    'csp' => strtolower(trim((string) env(
        'SECURITY_CSP',
        filter_var(env('SECURITY_CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL) ? 'report' : 'off',
    ))),
];
