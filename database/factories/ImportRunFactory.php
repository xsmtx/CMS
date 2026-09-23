<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportStatus;
use App\Infrastructure\Import\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
final class ImportRunFactory extends Factory
{
    protected $model = ImportRun::class;

    public function definition(): array
    {
        return [
            'source' => 'whmcs',
            'mode' => ImportMode::DryRun->value,
            'status' => ImportStatus::Pending->value,
            'domains' => [ImportDomain::Customers->value],
            'expected' => null,
            'totals' => null,
        ];
    }

    public function live(): static
    {
        return $this->state(fn (): array => ['mode' => ImportMode::Live->value]);
    }

    /**
     * @param  list<ImportDomain>  $domains
     */
    public function forDomains(array $domains): static
    {
        return $this->state(fn (): array => [
            'domains' => array_map(
                static fn (ImportDomain $domain): string => $domain->value,
                $domains,
            ),
        ]);
    }
}
