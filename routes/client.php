<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Client\ApiTokenController;
use App\Http\Controllers\Client\BillingDetailsController;
use App\Http\Controllers\Client\ContactController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\OrderController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\TransactionController;
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

    Route::prefix('billing')->name('billing.')->group(function (): void {
        Route::get('/', [InvoiceController::class, 'index'])->name('invoices');
        Route::get('invoices/{number}', [InvoiceController::class, 'show'])->name('invoice');
        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions');
        Route::get('details', [BillingDetailsController::class, 'show'])->name('details');
        Route::put('details', [BillingDetailsController::class, 'update'])->name('details.update');

        // Removing a stored card is destructive and is the account
        // owner's, not an impersonating operator's.
        Route::middleware('impersonation.blocked')->group(function (): void {
            Route::put('methods/{method}/default', [BillingDetailsController::class, 'makeDefault'])
                ->name('methods.default');
            Route::delete('methods/{method}', [BillingDetailsController::class, 'destroy'])
                ->name('methods.destroy');
        });
    });

    Route::get('services', [ServiceController::class, 'index'])->name('services');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('service');

    Route::get('domains', [DomainController::class, 'index'])->name('domains');
    Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domain');
    Route::put('domains/{domain}/nameservers', [DomainController::class, 'nameservers'])
        ->name('domains.nameservers');
    Route::put('domains/{domain}/auto-renew', [DomainController::class, 'autoRenew'])
        ->name('domains.auto-renew');
    // Fetched, shown once, never stored.
    Route::post('domains/{domain}/transfer-code', [DomainController::class, 'transferCode'])
        ->name('domains.transfer-code');

    Route::get('orders', [OrderController::class, 'index'])->name('orders');
    Route::get('orders/{number}', [OrderController::class, 'show'])->name('order');

    // Managing who else can reach the account is the account owner's, and
    // is blocked while a staff member is impersonating.
    Route::middleware('impersonation.blocked')->group(function (): void {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts');
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ContactController::class, 'update'])
            ->name('contacts.update');
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])
            ->name('contacts.destroy');

        // A token is a credential that outlives the session that made it.
        // Whatever an operator is impersonating a customer to fix, it is
        // not to walk out with one.
        Route::get('developer/tokens', [ApiTokenController::class, 'index'])->name('tokens');
        Route::post('developer/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
        Route::delete('developer/tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->name('tokens.destroy');
    });
});
