<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\AddBillableItem;
use App\Application\Billing\Exceptions\BillableItemRefused;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillableItemRequest;
use App\Infrastructure\Billing\Models\BillableItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * One-off charges waiting for a customer's next invoice.
 *
 * Two endpoints and no screen of their own: the panel lives on the customer's
 * detail page, because that is where somebody records an hour of work — and a
 * list of every charge on the installation is a list nobody opens.
 *
 * **`billing.invoices.manage`, not `crm.customers.manage`.** Somebody who may
 * edit a customer's address has not thereby been given the ability to put
 * money on their next invoice.
 */
final class BillableItemController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly AddBillableItem $items,
    ) {}

    public function store(BillableItemRequest $request, Customer $customer): RedirectResponse
    {
        $this->refuseUnlessPermitted();

        $data = $request->validated();

        $service = ($data['service'] ?? '') === ''
            ? null
            : Service::query()->whereKey($data['service'])->first();

        try {
            $this->items->handle(
                customer: $customer,
                description: $data['description'],
                /*
                 * The customer's own currency, never one the form chose. A
                 * charge in another would wait for an invoice that never
                 * comes, and there is no exchange rate in this product to
                 * rescue it.
                 */
                unitPrice: Money::ofMinor((int) $data['unit_amount_minor'], $customer->currency_code),
                quantity: (int) ($data['quantity'] ?? 1),
                service: $service instanceof Service ? $service : null,
                chargeOn: ($data['charge_on'] ?? '') === ''
                    ? null
                    : CarbonImmutable::parse($data['charge_on']),
                note: $data['note'] ?? null,
                actor: $this->actor->model(),
            );
        } catch (BillableItemRefused $refusal) {
            return back()->withErrors(['description' => $refusal->getMessage()])->withInput();
        }

        return back()->with('status', __('billing.billables.title'));
    }

    public function destroy(BillableItem $item): RedirectResponse
    {
        $this->refuseUnlessPermitted();

        try {
            $this->items->remove($item, $this->actor->model());
        } catch (BillableItemRefused $refusal) {
            return back()->withErrors(['item' => $refusal->getMessage()]);
        }

        return back()->with('status', __('billing.billables.remove'));
    }

    private function refuseUnlessPermitted(): void
    {
        if (! $this->actor->can('billing.invoices.manage')) {
            throw new ForbiddenException((string) __('billing.not_permitted'));
        }
    }
}
