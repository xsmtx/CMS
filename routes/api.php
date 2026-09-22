<?php

declare(strict_types=1);

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
