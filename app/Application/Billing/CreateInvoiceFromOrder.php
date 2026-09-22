<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\OrderNotInvoiceable;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Crm\AddressType;
use App\Domain\Ordering\LineKind;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Ordering\Models\OrderItemOption;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an order into a draft invoice.
 *
 * The lines are copied from the order lines, which were themselves copied
 * from the catalog — the same rule, one step further along. Nothing here
 * reads a price back through a product.
 *
 * The result is a draft. Issuing it is a separate decision, because an
 * operator may want to look first, and because issuing is what freezes the
 * document.
 */
final readonly class CreateInvoiceFromOrder
{
    public function handle(Order $order, ?Model $actor = null): Invoice
    {
        $this->assertInvoiceable($order);

        $order->load(['items.options', 'items.children.options', 'customer']);

        $invoice = DB::transaction(function () use ($order): Invoice {
            $invoice = Invoice::query()->create([
                'organization_id' => $order->organization_id,
                // A draft has no number: numbers come from a sequence and
                // handing one to a document that may never be issued
                // leaves a gap nobody can explain.
                'number' => 'DRAFT-'.Str::upper(Str::random(10)),
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'status' => InvoiceStatus::Draft->value,
                'currency_code' => $order->currency_code,
                'subtotal_minor' => $order->subtotal->minorUnits,
                'discount_minor' => $order->discount->minorUnits,
                'tax_minor' => $order->tax->minorUnits,
                'total_minor' => $order->total->minorUnits,
                'tax_breakdown' => $order->tax_breakdown,
                'tax_exemption_reason' => $order->tax_exemption_reason,
                'due_on' => CarbonImmutable::now()
                    ->addDays((int) config('platform.billing.due_days', 14))
                    ->toDateString(),
            ]);

            $this->writeLines($invoice, $order);

            return $invoice;
        });

        Audit::action('billing.invoice.created')
            ->by($actor)
            ->on($invoice)
            ->forOrganization($invoice->organization_id)
            ->withMetadata([
                'order' => $order->number,
                'total' => $order->total->toDecimalString(),
                'currency' => $order->currency_code,
            ])
            ->write();

        return $invoice;
    }

    /**
     * The party, as it is right now. Copied onto the invoice when it is
     * issued, and never read again.
     *
     * @return array<string, string|null>
     */
    public static function billTo(Customer $customer): array
    {
        $address = $customer->addressFor(AddressType::Billing);
        $contact = $customer->primaryContact;

        return [
            'bill_to_name' => $contact?->displayName() ?? $customer->displayName(),
            'bill_to_company' => $customer->company_name,
            'bill_to_tax_id' => $customer->tax_id,
            'bill_to_address' => $address === null ? null : implode("\n", array_filter([
                $address->line_one,
                $address->line_two,
                trim(($address->postal_code ?? '').' '.($address->city ?? '')),
                $address->region,
            ])),
            'bill_to_country' => $address?->country_code,
            'bill_to_email' => $contact?->email,
        ];
    }

    private function assertInvoiceable(Order $order): void
    {
        if ($order->status === OrderStatus::Draft || $order->status === OrderStatus::Cancelled) {
            throw OrderNotInvoiceable::status($order->status->value);
        }

        $existing = Invoice::query()
            ->where('order_id', $order->id)
            ->whereNot('status', InvoiceStatus::Cancelled->value)
            ->first();

        if ($existing instanceof Invoice) {
            throw OrderNotInvoiceable::alreadyInvoiced($existing->number);
        }
    }

    /**
     * One invoice line per order line, with the options folded into the
     * description.
     *
     * An invoice is read by a person and often by their accountant. A line
     * that says "Starter Plan — Monthly (Control panel: cPanel)" is worth
     * more than four rows that have to be reassembled by eye.
     */
    private function writeLines(Invoice $invoice, Order $order): void
    {
        $position = 0;

        foreach ($order->allItems as $item) {
            $invoice->items()->create([
                'organization_id' => $invoice->organization_id,
                'order_item_id' => $item->id,
                'description' => $this->describe($item),
                'detail' => $this->detail($item),
                'quantity' => $item->quantity,
                'currency_code' => $invoice->currency_code,
                'unit_amount_minor' => $item->unit_recurring->plus($item->unit_setup)->minorUnits,
                'line_amount_minor' => $item->line_recurring->plus($item->line_setup)->minorUnits,
                'discount_minor' => $item->line_discount->minorUnits,
                // Tax is charged on the order as a whole rather than per
                // line, so the lines carry none and the invoice totals
                // carry what was actually worked out.
                'tax_minor' => 0,
                'position' => $position++,
            ]);
        }
    }

    private function describe(OrderItem $item): string
    {
        $parts = [$item->name];

        if ($item->billing_cycle !== null) {
            $parts[] = (string) __('catalog.cycles.'.$item->billing_cycle->value);
        }

        if ($item->kind === LineKind::Domain && $item->domain !== null) {
            $parts[] = $item->domain;
        }

        return implode(' — ', $parts);
    }

    private function detail(OrderItem $item): ?string
    {
        $options = $item->options
            ->map(fn (OrderItemOption $option): string => $option->group_name.': '.$option->label)
            ->all();

        return $options === [] ? null : implode("\n", $options);
    }
}
