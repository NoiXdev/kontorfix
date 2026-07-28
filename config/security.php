<?php

return [
    // HSTS nur aktivieren, wenn TLS terminiert ist (Reverse Proxy). Default aus.
    'hsts' => env('SECURITY_HSTS', false),
    // CSP zunächst nur als Report-Only ausrollen (Inertia/Vite-kompatibel prüfen), dann durchsetzen.
    'csp_report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
];
