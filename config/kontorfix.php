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

];
