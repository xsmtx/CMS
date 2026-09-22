<?php

declare(strict_types=1);

use App\Http\Controllers\StorefrontCatalogController;
use App\Http\Controllers\StorefrontController;
use App\Http\Middleware\ResolveStorefrontOrganization;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public storefront
|--------------------------------------------------------------------------
|
| The admin and client areas are registered separately, in routes/admin.php
| and routes/client.php, from bootstrap/app.php.
|
*/

// Public pages get their organization boundary from the installation rather
// than from an actor, so the catalog they show is one brand's, not everyone's.
Route::middleware(ResolveStorefrontOrganization::class)->group(function (): void {
    Route::get('/', StorefrontController::class)->name('storefront.home');

    Route::get('/store', [StorefrontCatalogController::class, 'index'])->name('storefront.catalog');
    Route::post('/store/currency', [StorefrontCatalogController::class, 'chooseCurrency'])
        ->name('storefront.currency');
    Route::get('/store/{slug}', [StorefrontCatalogController::class, 'show'])
        ->name('storefront.product');
});
