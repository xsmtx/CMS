<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
final class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        return [
            'invoice_id' => fn (): string => Invoice::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['invoice_id']),
            'order_item_id' => null,
            'description' => 'Starter Plan — Monthly',
            'detail' => null,
            'quantity' => 1,
            'currency_code' => 'EUR',
            'unit_amount_minor' => 999,
            'line_amount_minor' => 999,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'tax_rate' => null,
            'period_start' => null,
            'period_end' => null,
            'position' => 0,
        ];
    }

    public function forInvoice(Invoice|string $invoice): static
    {
        $id = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return $this->state(fn (): array => [
            'invoice_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    private function organizationOf(string $invoiceId): string
    {
        return Invoice::query()
            ->withoutGlobalScope('organization')
            ->whereKey($invoiceId)
            ->firstOrFail()
            ->organization_id;
    }
}
