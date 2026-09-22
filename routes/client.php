<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Client\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client area
|--------------------------------------------------------------------------
|
| Authentication lives at the site root (/login), because that is where a
| customer expects to find it. The portal itself sits under /client.
|
*/

(require __DIR__.'/auth.php')(Guard::Client);

Route::middleware(['auth:client'])->prefix('client')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
});
