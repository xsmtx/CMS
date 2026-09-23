<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Automation\Models\DunningStep;
use App\Infrastructure\Automation\Models\InvoiceDunningStep;
use App\Infrastructure\Billing\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDunningStep>
 */
final class InvoiceDunningStepFactory extends Factory
{
    protected $model = InvoiceDunningStep::class;

    public function definition(): array
    {
        return [
            'invoice_id' => fn (): string => Invoice::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Invoice::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['invoice_id'])
                ->firstOrFail()
                ->organization_id,
            'step_id' => fn (): string => DunningStep::factory()->create()->id,
            'ran_at' => now(),
        ];
    }
}
