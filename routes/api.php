<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GatewayWebhookController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\RecordApiRequest;
use App\Http\Middleware\RequireApiScope;
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

    Route::middleware([
        RecordApiRequest::class,
        AuthenticateApiToken::class,
        'throttle:api',
        EnforceIdempotency::class,
    ])->group(function (): void {
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
