<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\BillableItemRefused;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\BillableItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Record a one-off charge for the next invoice to quote.
 *
 * **It raises nothing.** The charge waits for an invoice — the renewal sweep
 * picks it up, and raises one for a customer whose only due charge is this.
 * Charging immediately would be a second path that issues documents, and this
 * product has one (ADR 0023).
 *
 * **The currency is the customer's own**, never chosen on the form. A charge
 * in a currency the customer is not billed in would wait for an invoice that
 * never comes, which is the quietest way for a recorded charge to be lost.
 *
 * **A service only narrows the description.** The line's subject is the item
 * itself, because that is the row a payment has to find its way back to — the
 * rule `QuoteUsage` already follows for a snapshot.
 */
final readonly class AddBillableItem
{
    public function handle(
        Customer $customer,
        string $description,
        Money $unitPrice,
        int $quantity = 1,
        ?Service $service = null,
        ?CarbonImmutable $chargeOn = null,
        ?string $note = null,
        ?Model $actor = null,
    ): BillableItem {
        if ($quantity < 1) {
            throw BillableItemRefused::noQuantity();
        }

        if ($unitPrice->currency->code !== $customer->currency_code) {
            // A charge in a currency this customer is not billed in waits for
            // an invoice that never comes.
            throw BillableItemRefused::wrongCurrency(
                $unitPrice->currency->code,
                $customer->currency_code,
            );
        }

        if ($service !== null && $service->customer_id !== $customer->id) {
            throw BillableItemRefused::notTheirService();
        }

        $item = BillableItem::query()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'service_id' => $service?->id,
            'description' => $description,
            'quantity' => $quantity,
            'currency_code' => $unitPrice->currency->code,
            'unit_amount_minor' => $unitPrice->minorUnits,
            'charge_on' => $chargeOn?->toDateString(),
            'created_by' => $actor?->getKey(),
            'note' => $note,
        ]);

        Audit::action('billing.billable_item.added')
            ->by($actor)
            ->on($item)
            ->forOrganization($item->organization_id)
            ->because($note)
            ->withMetadata([
                // Minor units and a code, never a formatted string: an audit
                // row read six weeks later wants the figure.
                'amount_minor' => $item->total()->minorUnits,
                'currency' => $item->currency_code,
                'quantity' => $item->quantity,
                'charge_on' => $item->charge_on?->toDateString(),
            ])
            ->write();

        return $item;
    }

    /**
     * Take it back off the list.
     *
     * Only while it is uncharged: an item an invoice has quoted is on a frozen
     * document, and deleting the row would leave a line pointing at nothing.
     * A charge somebody regrets after it has been invoiced is a credit note.
     */
    public function remove(BillableItem $item, ?Model $actor = null): void
    {
        if ($item->isCharged()) {
            throw BillableItemRefused::alreadyCharged();
        }

        Audit::action('billing.billable_item.removed')
            ->by($actor)
            ->on($item)
            ->forOrganization($item->organization_id)
            ->write();

        $item->delete();
    }
}
