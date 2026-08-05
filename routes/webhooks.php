<?php

use App\Http\Controllers\Webhook\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

// Public, signature-gated endpoints. Throttle as DoS defense-in-depth in addition
// to the signature check (a valid caller should not be able to spam the O(n) match).
// Per-record endpoint (each configured hook has its own secret + URL):
Route::post('/webhooks/{provider}/{hook}', [IncomingWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');
// Legacy shared endpoint using the env secret (kept for backwards compatibility):
Route::post('/webhooks/{provider}', [IncomingWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');
