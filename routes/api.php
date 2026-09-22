<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GatewayWebhookController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Every route is versioned, returns the platform error envelope on failure
| and carries a correlation identifier. Token-scoped resource routes are
| added by the phase that introduces the resource.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)
        ->middleware('throttle:60,1')
        ->name('health');
});

/*
|--------------------------------------------------------------------------
| Gateway webhooks
|--------------------------------------------------------------------------
|
| Deliberately outside the versioned API: a provider's callback URL is
| configured once, in their dashboard, and must not move when this
| platform's own API version does.
|
| Unauthenticated by necessity and by design — the proof is the signature on
| the body. The rate limit is generous because a gateway catching up after
| an outage delivers in bursts, and dropping those loses payments.
|
*/
Route::post('webhooks/payments/{gateway}', GatewayWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('webhooks.payments');
