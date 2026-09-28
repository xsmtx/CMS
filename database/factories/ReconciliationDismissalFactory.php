<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Intelligence\Models\ReconciliationDismissal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationDismissal>
 */
final class ReconciliationDismissalFactory extends Factory
{
    protected $model = ReconciliationDismissal::class;

    public function definition(): array
    {
        return [
            'source' => 'service',
            'resource' => 'service',
            'field' => 'status',
            'reason' => 'Built by hand for the migration.',
            'dismissed_at' => CarbonImmutable::now(),
        ];
    }

    public function until(CarbonImmutable $moment): self
    {
        return $this->state(fn (): array => ['until' => $moment]);
    }
}
