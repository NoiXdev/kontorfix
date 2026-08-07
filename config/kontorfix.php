<?php

return [

    /*
    |--------------------------------------------------------------------------
    | npm publish limits
    |--------------------------------------------------------------------------
    |
    | Upper bound for a single uploaded npm tarball. Protects against
    | memory/disk exhaustion by publish-token holders. In bytes.
    |
    */

    'npm_max_tarball_bytes' => (int) env('KONTORFIX_NPM_MAX_TARBALL_BYTES', 100 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | PyPI upload limits
    |--------------------------------------------------------------------------
    |
    | Upper bound for a single uploaded Python distribution (sdist or wheel),
    | against memory/disk exhaustion by publish-token holders. In bytes.
    |
    */

    'python_max_dist_bytes' => (int) env('KONTORFIX_PYTHON_MAX_DIST_BYTES', 200 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Registry token default lifetime
    |--------------------------------------------------------------------------
    |
    | Default lifetime in days for newly issued registry tokens when the issuer does
    | not pick an expiry. 0 (the default) means open-ended, which is what every
    | existing install has today — raising it never touches tokens that already exist,
    | it only applies to tokens issued from then on.
    |
    */

    'registry_token_ttl_days' => (int) env('KONTORFIX_REGISTRY_TOKEN_TTL_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | Upstream cache TTL
    |--------------------------------------------------------------------------
    |
    | Validity period of cached upstream metadata in seconds, before a
    | fresh fetch from the upstream is triggered.
    |
    */

    'upstream_cache_ttl' => (int) env('KONTORFIX_UPSTREAM_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Incoming webhook secret
    |--------------------------------------------------------------------------
    |
    | Shared secret for signature verification of incoming webhooks (e.g. from CI
    | systems) that trigger a sync.
    |
    */

    'incoming_webhook_secret' => env('KONTORFIX_INCOMING_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | First-run setup
    |--------------------------------------------------------------------------
    |
    | Whether the first-run wizard demands the setup token printed by
    | `setup:token` at boot. The wizard creates the instance owner without
    | authentication, so this must fail closed: null means "decide from the
    | environment", which demands the token everywhere except local development
    | and the test suite. Set it to false only for a deployment you accept
    | anyone can claim.
    |
    */

    'setup' => [
        'require_token' => env('KONTORFIX_SETUP_REQUIRE_TOKEN') === null
            ? null
            : filter_var(env('KONTORFIX_SETUP_REQUIRE_TOKEN'), FILTER_VALIDATE_BOOL),
    ],

];
