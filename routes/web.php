<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public storefront
|--------------------------------------------------------------------------
*/

Route::get('/', StorefrontController::class)->name('storefront.home');

/*
|--------------------------------------------------------------------------
| Client area
|--------------------------------------------------------------------------
|
| Authentication routes arrive in Phase 1; until then the shell is reachable
| only by an authenticated session created in tests or tinker.
|
*/

Route::middleware(['auth'])
    ->prefix('client')
    ->name('client.')
    ->group(function (): void {
        Route::get('/', ClientDashboardController::class)->name('dashboard');
    });

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
    });
