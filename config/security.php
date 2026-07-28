<?php

return [
    // Only enable HSTS if TLS is terminated (reverse proxy). Default off.
    'hsts' => env('SECURITY_HSTS', false),
    // Roll out CSP as report-only first (verify Inertia/Vite compatibility), then enforce.
    'csp_report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
];
