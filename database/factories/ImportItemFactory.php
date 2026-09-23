<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportOutcome;
use App\Infrastructure\Import\Models\ImportItem;
use App\Infrastructure\Import\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportItem>
 */
final class ImportItemFactory extends Factory
{
    protected $model = ImportItem::class;

    public function definition(): array
    {
        return [
            'run_id' => fn (): string => ImportRun::factory()->create()->id,
            'domain' => ImportDomain::Customers->value,
            'external_id' => (string) fake()->unique()->numberBetween(1, 99999),
            'outcome' => ImportOutcome::Created->value,
            'label' => fake()->company(),
        ];
    }
}
