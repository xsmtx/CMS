<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNote>
 */
final class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    public function definition(): array
    {
        return [
            'invoice_id' => fn (): string => Invoice::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['invoice_id']),
            'number' => 'CN-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'currency_code' => 'EUR',
            'amount_minor' => 500,
            'reason' => 'Billed in error',
            'issued_by' => null,
            'issued_on' => now()->toDateString(),
        ];
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
