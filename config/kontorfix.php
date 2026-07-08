<?php

return [

    /*
    |--------------------------------------------------------------------------
    | npm-Publish-Limits
    |--------------------------------------------------------------------------
    |
    | Obergrenze für einen einzelnen hochgeladenen npm-Tarball. Schützt vor
    | Memory-/Disk-Erschöpfung durch Publish-Token-Inhaber. In Bytes.
    |
    */

    'npm_max_tarball_bytes' => (int) env('KONTORFIX_NPM_MAX_TARBALL_BYTES', 100 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Upstream-Cache-TTL
    |--------------------------------------------------------------------------
    |
    | Gültigkeitsdauer gecachter Upstream-Metadaten in Sekunden, bevor ein
    | erneuter Fetch beim Upstream ausgelöst wird.
    |
    */

    'upstream_cache_ttl' => (int) env('KONTORFIX_UPSTREAM_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Incoming-Webhook-Secret
    |--------------------------------------------------------------------------
    |
    | Shared Secret zur Signaturprüfung eingehender Webhooks (z. B. von CI-
    | Systemen), die einen Sync anstoßen.
    |
    */

    'incoming_webhook_secret' => env('KONTORFIX_INCOMING_WEBHOOK_SECRET'),

];
