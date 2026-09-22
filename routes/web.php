<?php

declare(strict_types=1);

use App\Http\Controllers\StorefrontCartController;
use App\Http\Controllers\StorefrontCatalogController;
use App\Http\Controllers\StorefrontCheckoutController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\StorefrontInvoiceController;
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
    Route::get('/store/{slug}/configure', [StorefrontCatalogController::class, 'configure'])
        ->name('storefront.configure');

    // The cart. Every mutation is a POST or a DELETE: a crawler following
    // links must not be able to fill or empty anyone's basket.
    Route::get('/cart', [StorefrontCartController::class, 'show'])->name('storefront.cart');
    Route::post('/cart', [StorefrontCartController::class, 'store'])->name('storefront.cart.store');
    Route::put('/cart/items/{item}', [StorefrontCartController::class, 'update'])
        ->name('storefront.cart.update');
    Route::delete('/cart/items/{item}', [StorefrontCartController::class, 'destroy'])
        ->name('storefront.cart.remove');
    Route::post('/cart/code', [StorefrontCartController::class, 'applyCode'])
        ->name('storefront.cart.code');
    Route::delete('/cart/code', [StorefrontCartController::class, 'removeCode'])
        ->name('storefront.cart.code.remove');

    Route::get('/checkout', [StorefrontCheckoutController::class, 'show'])->name('storefront.checkout');
    Route::post('/checkout', [StorefrontCheckoutController::class, 'store'])
        ->name('storefront.checkout.store');
    Route::get('/orders/{number}', [StorefrontCheckoutController::class, 'confirmation'])
        ->name('storefront.order');

    // Paying an invoice. Either the contact it belongs to, or the browser
    // that placed the order — an account created at checkout has no
    // password until the reset mail arrives, and an invoice nobody can
    // reach is not an invoice anybody pays. The controller decides; a
    // number on its own opens nothing.
    Route::get('/invoices/{number}', [StorefrontInvoiceController::class, 'show'])
        ->name('storefront.invoice');
    Route::post('/invoices/{number}/pay', [StorefrontInvoiceController::class, 'pay'])
        ->name('storefront.invoice.pay');

    // Where a gateway sends the customer back. It confirms nothing:
    // whatever the query string claims, the page shows only what a
    // verified webhook has already recorded.
    Route::get('/invoices/{number}/returned', [StorefrontInvoiceController::class, 'returned'])
        ->name('storefront.invoice.returned');
});
