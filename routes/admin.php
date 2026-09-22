<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
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

    // Acting as a customer. Starting it is rate limited on top of the
    // permission and boundary checks.
    Route::post('contacts/{contact}/impersonate', [ImpersonationController::class, 'store'])
        ->name('contacts.impersonate');
});
