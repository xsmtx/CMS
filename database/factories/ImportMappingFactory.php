<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Import\ImportDomain;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Import\Models\ImportMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportMapping>
 */
final class ImportMappingFactory extends Factory
{
    protected $model = ImportMapping::class;

    public function definition(): array
    {
        return [
            'source' => 'whmcs',
            'domain' => ImportDomain::Customers->value,
            'external_id' => (string) fake()->unique()->numberBetween(1, 99999),
            'target_type' => Customer::class,
            'target_id' => fn (): string => Customer::factory()->create()->id,
        ];
    }
}
