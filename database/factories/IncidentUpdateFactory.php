<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentUpdate>
 */
final class IncidentUpdateFactory extends Factory
{
    protected $model = IncidentUpdate::class;

    public function definition(): array
    {
        return [
            'state' => IncidentState::Investigating,
            'body' => 'We are looking at it.',
            'is_public' => false,
        ];
    }
}
