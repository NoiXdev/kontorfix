<?php

use App\Http\Controllers\Webhook\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

// Public, signature-gated endpoint. Throttle as DoS defense-in-depth in addition
// to the signature check (a valid caller should not be able to spam the O(n) match).
Route::post('/webhooks/{provider}', [IncomingWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');
