<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Client\ContactController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\ProfileController;
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

    // Stopping impersonation happens from inside the customer session, which
    // is the only place the banner is visible.
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])
        ->name('impersonation.stop');

    Route::get('profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/customer', [ProfileController::class, 'updateCustomer'])
        ->name('profile.customer');

    // Managing who else can reach the account is the account owner's, and
    // is blocked while a staff member is impersonating.
    Route::middleware('impersonation.blocked')->group(function (): void {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts');
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ContactController::class, 'update'])
            ->name('contacts.update');
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])
            ->name('contacts.destroy');
    });
});
