<?php

use App\Http\Controllers\Webhook\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{provider}', [IncomingWebhookController::class, 'handle']);
