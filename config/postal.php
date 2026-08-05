<?php

use SynergiTech\Postal\Models\Email;
use SynergiTech\Postal\Models\Email\Webhook;

/*
|--------------------------------------------------------------------------
| Postal
|--------------------------------------------------------------------------
|
| Configuration for synergitech/laravel-postal. `domain` and `key` are only
| fallbacks here — the effective values come from the DB-backed mail settings
| and are applied at boot by App\Providers\MailServiceProvider, so admins can
| change the mail backend without a redeploy.
|
*/

return [
    // HTTPS URL of the Postal server.
    'domain' => env('POSTAL_DOMAIN'),

    // API credential belonging to the same mail server as the sending domain.
    'key' => env('POSTAL_KEY'),

    'models' => [
        'email' => env('POSTAL_MODELS_EMAIL', Email::class),
        'webhook' => env('POSTAL_MODELS_WEBHOOK', Webhook::class),
    ],

    'enable' => [
        'emaillogging' => env('POSTAL_ENABLE_EMAILLOG', true),
        // Off by default: enabling it registers a public route, which is an
        // opt-in decision for the operator rather than something a fresh
        // install should expose. Requires emaillogging to do anything.
        'webhookreceiving' => env('POSTAL_ENABLE_WEBHOOKRECEIVE', false),
    ],

    'webhook' => [
        'route' => env('POSTAL_WEBHOOK_ROUTE', '/postal/webhook'),
        // Verifies the X-Postal-Signature header — never turn this off in production.
        'verify' => env('POSTAL_WEBHOOK_VERIFY', true),
        // DKIM record "p" value of the Postal server, without the trailing semicolon.
        'public_key' => env('POSTAL_WEBHOOK_PUBLIC_KEY', ''),
    ],
];
