<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Provisioning\SearchServiceAddons;
use App\Http\Controllers\Controller;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The addons customers are paying for.
 *
 * One level below the products and services screen and asked the same way,
 * because it is the same question: what is this customer paying for, and
 * when does it renew.
 *
 * Read-only on purpose. An addon is sold with a product — at checkout or by
 * an operator building an order — and a screen that let somebody conjure a
 * billable row out of nothing would produce a charge with no order behind
 * it. What an operator does here is find one and follow it back.
 */
final class ServiceAddonController extends Controller
{
    public function index(Request $request, SearchServiceAddons $search): Response
    {
        // Authorized against services: an addon is part of one, and a
        // second permission for the same records would be a second answer
        // to one question.
        $this->authorize('viewAny', Service::class);

        /** @var array<string, mixed> $criteria */
        $criteria = $request->only([
            'product_type', 'server', 'product', 'gateway',
            'billing_cycle', 'status', 'domain', 'client',
            'custom_field', 'custom_value',
        ]);

        $includeInactive = $request->boolean('inactive');

        $addons = $search->paginate($criteria, includeInactiveClients: $includeInactive);

        return Inertia::render('Admin/Services/Addons', [
            'addons' => [
                'data' => array_map($this->row(...), $addons->items()),
                'currentPage' => $addons->currentPage(),
                'lastPage' => $addons->lastPage(),
                'total' => $addons->total(),
            ],
            'filters' => [...$criteria, 'inactive' => $includeInactive],
            'schema' => $search->schema(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ServiceAddon $addon): array
    {
        $service = $addon->service;
        $card = $addon->customer?->defaultPaymentMethod;

        return [
            'id' => $addon->id,
            'name' => $addon->name,
            'status' => $addon->status->value,
            'statusLabel' => (string) __($addon->status->labelKey()),
            'service' => $service?->name,
            'serviceId' => $addon->service_id,
            'customer' => $addon->customer?->displayName(),
            'customerId' => $addon->customer_id,
            'billingCycleLabel' => $addon->billing_cycle === null
                ? null
                : (string) __($addon->billing_cycle->labelKey()),
            'recurring' => $addon->recurring->format(app()->getLocale()),
            'nextDueOn' => $addon->next_due_on?->toDateString(),
            'detail' => [
                'orderNumber' => $addon->order?->number,
                'orderId' => $addon->order_id,
                'domain' => $service?->domain,
                'server' => $service?->server?->name,
                'product' => $service?->product?->name,
                'paymentMethod' => $card === null
                    ? null
                    : trim($card->gateway.' '.($card->brand ?? '').' '.($card->last_four === null ? '' : '•••• '.$card->last_four)),
                'registeredOn' => ($addon->starts_on ?? $addon->created_at)?->toDateString(),
                'quantity' => $addon->quantity,
                'setup' => $addon->setup->format(app()->getLocale()),
                // What it was in the catalog. Null once that entry is
                // retired, which does not take the paying row away.
                'catalogName' => $addon->addon?->name,
            ],
        ];
    }
}
