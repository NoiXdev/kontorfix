<?php

use App\Services\Http\AppUrl;

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Published deliberately. Without this file the framework default applied, which
| answers every `/api/*` request with `Access-Control-Allow-Origin: *` and every
| preflight with a 204 — so any page a user visits could read the unauthenticated
| `/api/*` surface, including its 401-vs-404 existence differences, and an instance on
| an internal network was readable from a browser inside that network. There was also
| no file for an operator to look at, so nothing to notice and no knob to turn.
|
| `supports_credentials` stays false, so a browser never attaches the session cookie or
| a bearer header to a cross-origin read; that is what kept the wildcard from being
| worse than it was, and it is not a reason to keep the wildcard.
|
| The default allowlist is the instance's own origin, which is what every first-party
| caller uses and is same-origin anyway — the registry protocol clients (Composer, npm,
| pip) are not browsers and are unaffected by CORS in either direction, and the routes
| they use are not under `api/` to begin with. An operator who runs a separate
| front-end sets KONTORFIX_CORS_ALLOWED_ORIGINS to a comma-separated list of origins.
| An APP_URL that names no host yields an empty list, i.e. no cross-origin access at
| all, which is the safe direction for an unconfigured instance.
|
| `env()` rather than `config()`: config files are loaded before the container can
| answer `config()`. The value is normalised through AppUrl so a scheme-less APP_URL
| still produces a usable origin here.
*/

$configured = trim((string) env('KONTORFIX_CORS_ALLOWED_ORIGINS', ''));

$origins = $configured !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $configured))))
    : array_values(array_filter([AppUrl::normalizeRoot(env('APP_URL'))]));

return [

    // `sanctum/csrf-cookie` is not registered by this application.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
