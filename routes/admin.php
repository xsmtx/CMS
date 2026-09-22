<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Admin\AddonController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OptionGroupController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\ProductPricingController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
|
| Mounted at /admin with the `admin.` name prefix and the staff guard. The
| shared auth controllers read the guard from that name prefix.
|
*/

(require __DIR__.'/auth.php')(Guard::Staff);

Route::middleware(['auth:staff'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Staff and role administration. Every action is authorized by a policy
    // that asks the boundary question before the permission question.
    Route::resource('staff', StaffController::class)
        ->parameters(['staff' => 'staff'])
        ->except(['show']);

    Route::delete('staff/{staff}/two-factor', [StaffController::class, 'disableTwoFactor'])
        ->name('staff.two-factor.disable');

    Route::resource('roles', RoleController::class)->except(['show']);

    // Customers, and the contacts that belong to them.
    Route::resource('customers', CustomerController::class)->except(['destroy']);

    Route::get('customers/{customer}/export', [CustomerController::class, 'export'])
        ->name('customers.export');
    Route::post('customers/{customer}/anonymize', [CustomerController::class, 'anonymize'])
        ->name('customers.anonymize');

    Route::resource('customers.contacts', ContactController::class)->except(['index', 'show']);

    // The catalog. Pricing sits on its own routes because it answers to
    // its own permission.
    Route::prefix('catalog')->name('catalog.')->group(function (): void {
        Route::resource('groups', ProductGroupController::class)->except(['show']);
        Route::resource('products', ProductController::class)->except(['show']);

        Route::get('products/{product}/pricing', [ProductPricingController::class, 'edit'])
            ->name('products.pricing.edit');
        Route::put('products/{product}/pricing', [ProductPricingController::class, 'update'])
            ->name('products.pricing.update');

        Route::resource('products.options', OptionGroupController::class)
            ->parameters(['options' => 'group'])
            ->except(['show']);

        Route::resource('products.addons', AddonController::class)->except(['show']);
        Route::put('products/{product}/addons/{addon}/pricing', [AddonController::class, 'pricing'])
            ->name('products.addons.pricing');

        Route::resource('currencies', CurrencyController::class)->except(['show']);
    });

    // Acting as a customer. Starting it is rate limited on top of the
    // permission and boundary checks.
    Route::post('contacts/{contact}/impersonate', [ImpersonationController::class, 'store'])
        ->name('contacts.impersonate');
});
