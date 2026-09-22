<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\PaymentStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['customer_id']),
            'invoice_id' => null,
            'gateway' => 'manual',
            'status' => PaymentStatus::Completed->value,
            'currency_code' => 'EUR',
            'amount_minor' => 999,
            'refunded_minor' => 0,
            'reference' => 'ref_'.Str::lower(Str::random(16)),
            'idempotency_key' => Str::lower(Str::random(24)),
            'failure_reason' => null,
            'received_at' => now(),
            'failed_at' => null,
            'recorded_by' => null,
            'note' => null,
        ];
    }

    public function forInvoice(Invoice|string $invoice): static
    {
        $id = $invoice instanceof Invoice ? $invoice->id : $invoice;

        $model = Invoice::query()->withoutGlobalScope('organization')->whereKey($id)->firstOrFail();

        return $this->state(fn (): array => [
            'invoice_id' => $model->id,
            'customer_id' => $model->customer_id,
            'organization_id' => $model->organization_id,
            'currency_code' => $model->currency_code,
        ]);
    }

    public function status(PaymentStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status->value,
            'received_at' => $status->isSuccessful() ? now() : null,
        ]);
    }

    public function of(int $minor): static
    {
        return $this->state(fn (): array => ['amount_minor' => $minor]);
    }

    private function organizationOf(string $customerId): string
    {
        return Customer::query()
            ->withoutGlobalScope('organization')
            ->whereKey($customerId)
            ->firstOrFail()
            ->organization_id;
    }
}
