<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GatewayWebhookController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\Staff\AlertController as StaffAlertController;
use App\Http\Controllers\Api\V1\Staff\IncidentController as StaffIncidentController;
use App\Http\Controllers\Api\V1\Staff\RemoteHandsController as StaffRemoteHandsController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateStaffApiToken;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\RecordApiRequest;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\RequireStaffApiScope;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Every route is versioned, returns the platform error envelope on failure
| and carries a correlation identifier.
|
| The middleware order is the authorization order, and it is deliberate:
| record the request whatever happens, authenticate the token, rate-limit
| what that token may ask for, make the write replayable, and only then let
| the scope decide. A scope check that ran before authentication would be
| checking nobody's scopes.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // Unauthenticated on purpose: a monitoring system needs to know this
    // installation is alive without holding a credential for it.
    Route::get('health', HealthController::class)
        ->middleware('throttle:60,1')
        ->name('health');

    /*
     * Where a device session begins and renews (ADR 0049).
     *
     * Unauthenticated by necessity: the first takes a password and the
     * second takes a refresh token, and each *is* the credential. Both are
     * throttled by address, and the first is throttled a second time by
     * identity inside `AuthenticateUser`.
     */
    Route::middleware([RecordApiRequest::class, 'throttle:api-device'])->group(function (): void {
        Route::post('auth/token', [DeviceTokenController::class, 'store'])->name('auth.token');
        Route::post('auth/refresh', [DeviceTokenController::class, 'refresh'])->name('auth.refresh');
    });

    Route::middleware([
        RecordApiRequest::class,
        AuthenticateApiToken::class,
        'throttle:api',
        EnforceIdempotency::class,
    ])->group(function (): void {
        /*
         * Signing out: the calling device, everywhere. No scope, because a
         * token being able to end itself is not a privilege — and a token
         * that could not would leave an app with no way to sign out.
         */
        Route::delete('auth/token', [DeviceTokenController::class, 'destroy'])->name('auth.revoke');

        Route::get('profile', ProfileController::class)
            ->middleware(RequireApiScope::class.':profile:read')
            ->name('profile');

        Route::get('services', [ServiceController::class, 'index'])
            ->middleware(RequireApiScope::class.':services:read')
            ->name('services.index');
        Route::get('services/{service}', [ServiceController::class, 'show'])
            ->middleware(RequireApiScope::class.':services:read')
            ->name('services.show');
        Route::post('services/{service}/actions/{action}', [ServiceController::class, 'action'])
            ->middleware(RequireApiScope::class.':services:write')
            ->name('services.action');

        Route::get('domains', [DomainController::class, 'index'])
            ->middleware(RequireApiScope::class.':domains:read')
            ->name('domains.index');
        Route::get('domains/{domain}', [DomainController::class, 'show'])
            ->middleware(RequireApiScope::class.':domains:read')
            ->name('domains.show');
        Route::post('domains/{domain}/nameservers', [DomainController::class, 'nameservers'])
            ->middleware(RequireApiScope::class.':domains:write')
            ->name('domains.nameservers');
        Route::post('domains/{domain}/renew', [DomainController::class, 'renew'])
            ->middleware(RequireApiScope::class.':domains:write')
            ->name('domains.renew');

        Route::get('invoices', [InvoiceController::class, 'index'])
            ->middleware(RequireApiScope::class.':invoices:read')
            ->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware(RequireApiScope::class.':invoices:read')
            ->name('invoices.show');

        Route::get('orders', [OrderController::class, 'index'])
            ->middleware(RequireApiScope::class.':orders:read')
            ->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->middleware(RequireApiScope::class.':orders:read')
            ->name('orders.show');

        Route::get('tickets', [TicketController::class, 'index'])
            ->middleware(RequireApiScope::class.':tickets:read')
            ->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])
            ->middleware(RequireApiScope::class.':tickets:read')
            ->name('tickets.show');
        Route::post('tickets', [TicketController::class, 'store'])
            ->middleware(RequireApiScope::class.':tickets:write')
            ->name('tickets.store');
        Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])
            ->middleware(RequireApiScope::class.':tickets:write')
            ->name('tickets.reply');

        Route::get('webhooks', [WebhookEndpointController::class, 'index'])
            ->middleware(RequireApiScope::class.':webhooks:read')
            ->name('webhooks.index');
        Route::get('webhooks/{endpoint}/deliveries', [WebhookEndpointController::class, 'deliveries'])
            ->middleware(RequireApiScope::class.':webhooks:read')
            ->name('webhooks.deliveries');
        Route::post('webhooks', [WebhookEndpointController::class, 'store'])
            ->middleware(RequireApiScope::class.':webhooks:write')
            ->name('webhooks.store');
        Route::delete('webhooks/{endpoint}', [WebhookEndpointController::class, 'destroy'])
            ->middleware(RequireApiScope::class.':webhooks:write')
            ->name('webhooks.destroy');
        Route::post('webhooks/deliveries/{delivery}/redeliver', [WebhookEndpointController::class, 'redeliver'])
            ->middleware(RequireApiScope::class.':webhooks:write')
            ->name('webhooks.redeliver');
    });

    /*
    |--------------------------------------------------------------------------
    | Staff surface (ADR 0049)
    |--------------------------------------------------------------------------
    |
    | Deliberately smaller than the admin area, permanently. Every endpoint
    | calls an application use case that already exists; the ones that would
    | need a new use case written are the ones that do not belong on a phone.
    |
    | **What is absent is the policy.** Firewall apply, power actions,
    | termination, restore, drain and every bulk endpoint have no route here,
    | because a client can be rewritten — so leaving a button out of an
    | application enforces nothing and the refusal has to be the absence of
    | the endpoint. `StaffApiSurfaceTest` walks these routes and fails if one
    | appears.
    |
    */
    Route::prefix('staff')->name('staff.')->group(function (): void {
        // The authentication itself, throttled like the client's.
        Route::middleware([RecordApiRequest::class, 'throttle:api-device'])->group(function (): void {
            Route::post('auth/token', [DeviceTokenController::class, 'store'])->name('auth.token');
            Route::post('auth/refresh', [DeviceTokenController::class, 'refresh'])->name('auth.refresh');
        });

        Route::middleware([
            RecordApiRequest::class,
            AuthenticateStaffApiToken::class,
            'throttle:api',
            EnforceIdempotency::class,
        ])->group(function (): void {
            Route::delete('auth/token', [DeviceTokenController::class, 'destroy'])->name('auth.revoke');

            Route::get('alerts', [StaffAlertController::class, 'index'])
                ->middleware(RequireStaffApiScope::class.':alerts:read')
                ->name('alerts.index');

            Route::get('incidents', [StaffIncidentController::class, 'index'])
                ->middleware(RequireStaffApiScope::class.':incidents:read')
                ->name('incidents.index');
            Route::get('incidents/{incident}', [StaffIncidentController::class, 'show'])
                ->middleware(RequireStaffApiScope::class.':incidents:read')
                ->name('incidents.show');
            Route::post('incidents/{incident}/updates', [StaffIncidentController::class, 'update'])
                ->middleware(RequireStaffApiScope::class.':incidents:write')
                ->name('incidents.update');
            Route::post('incidents/{incident}/resolve', [StaffIncidentController::class, 'resolve'])
                ->middleware(RequireStaffApiScope::class.':incidents:write')
                ->name('incidents.resolve');

            Route::get('remote-hands', [StaffRemoteHandsController::class, 'index'])
                ->middleware(RequireStaffApiScope::class.':remote_hands:read')
                ->name('remote_hands.index');
            Route::post('remote-hands/{task}/move', [StaffRemoteHandsController::class, 'move'])
                ->middleware(RequireStaffApiScope::class.':remote_hands:write')
                ->name('remote_hands.move');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Gateway webhooks
|--------------------------------------------------------------------------
|
| Deliberately outside the versioned API: a provider's callback URL is
| configured once, in their dashboard, and must not move when this
| platform's own API version does.
|
| Unauthenticated by necessity and by design — the proof is the signature on
| the body. The rate limit is generous because a gateway catching up after
| an outage delivers in bursts, and dropping those loses payments.
|
*/
Route::post('webhooks/payments/{gateway}', GatewayWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('webhooks.payments');
