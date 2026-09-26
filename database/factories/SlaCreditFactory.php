<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Reliability\Models\SlaCredit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaCredit>
 */
final class SlaCreditFactory extends Factory
{
    protected $model = SlaCredit::class;

    public function definition(): array
    {
        return [
            'amount_minor' => 1_000,
            'currency_code' => 'EUR',
            'reason' => 'Four hours of downtime on the shared cluster.',
        ];
    }
}
