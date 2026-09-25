<?php

declare(strict_types=1);

use App\Domain\Identity\Guard;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SecurityController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Registered once per guard by routes/admin.php and routes/client.php. The
| flow is identical on both surfaces; only the guard, the URL prefix and the
| route-name prefix differ, so sharing the definitions is what keeps the two
| from drifting apart.
|
| The controllers read the guard from the route-name prefix the caller has
| already applied, so nothing extra has to be wired up per route.
|
*/

return function (Guard $guard): void {
    Route::middleware('guest:'.$guard->value)->group(function (): void {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('login.store');

        Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('forgot-password', [PasswordResetController::class, 'email'])
            ->middleware('throttle:6,1')
            ->name('password.email');

        Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('reset-password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('password.update');

        // The challenge sits between verified credentials and a granted
        // session, so it is reachable only while signed out.
        Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
            ->name('two-factor.challenge');
        Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('two-factor.verify');
    });

    Route::middleware('auth:'.$guard->value)->group(function (): void {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        /*
         * "It is still you, isn't it." Signed in, and asked for the password
         * again before something that cannot be undone.
         *
         * Throttled at the route as well as per account in the controller: the
         * form is a password oracle against a session somebody may already
         * have stolen, and it leaks the account name for free.
         */
        Route::get('confirm-password', [ConfirmPasswordController::class, 'create'])
            ->name('password.confirm');
        Route::post('confirm-password', [ConfirmPasswordController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('password.confirm.store');

        /*
         * The language somebody reads the panel in, written on their own
         * row. Blocked during impersonation with the security settings and
         * for the same reason: an operator standing in for a customer is
         * there to fix something, not to change what language that customer
         * reads their invoices in.
         */
        Route::middleware('impersonation.blocked')
            ->put('locale', [LocaleController::class, 'update'])
            ->name('locale');

        // Security settings belong to the person signed in, and none of it
        // is available to someone impersonating them.
        Route::middleware('impersonation.blocked')->prefix('security')->name('security.')->group(function (): void {
            Route::get('/', [SecurityController::class, 'show'])->name('show');
            Route::put('password', [SecurityController::class, 'updatePassword'])->name('password');

            Route::post('two-factor', [SecurityController::class, 'beginTwoFactor'])->name('two-factor.begin');
            Route::get('two-factor', [SecurityController::class, 'twoFactorSetup'])->name('two-factor.setup');
            Route::post('two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])
                ->name('two-factor.confirm');
            Route::post('two-factor/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])
                ->name('two-factor.recovery-codes');
            Route::delete('two-factor', [SecurityController::class, 'disableTwoFactor'])->name('two-factor.disable');

            Route::delete('sessions', [SecurityController::class, 'revokeOtherSessions'])->name('sessions.others');
            Route::delete('sessions/{session}', [SecurityController::class, 'revokeSession'])->name('sessions.revoke');
        });
    });
};
