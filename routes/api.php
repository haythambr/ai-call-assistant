<?php

use App\Http\Controllers\Api\CallWebhookController;
use Illuminate\Support\Facades\Route;

// Each account sends finished calls to its own URL (shown on Settings). The
// URL without a token only completes outbound calls this app placed.
Route::post('/webhooks/calls/{token?}', [CallWebhookController::class, 'store'])
    ->middleware(['webhook.signature', 'throttle:120,1'])
    ->where('token', '[a-z0-9]{40}')
    ->name('webhooks.calls');
