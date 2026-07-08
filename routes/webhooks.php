<?php

use App\Http\Controllers\Webhook\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

// Öffentlicher, signatur-gated Endpoint. Throttle als DoS-Defense-in-depth zusätzlich
// zur Signaturprüfung (ein gültiger Aufrufer soll den O(n)-Match nicht spammen können).
Route::post('/webhooks/{provider}', [IncomingWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');
