<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Security\AbuseState;
use App\Infrastructure\Security\Models\AbuseCaseEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbuseCaseEvent>
 */
final class AbuseCaseEventFactory extends Factory
{
    protected $model = AbuseCaseEvent::class;

    public function definition(): array
    {
        return [
            'state' => AbuseState::Open,
            'body' => 'Complaint received and queued for review.',
        ];
    }
}
