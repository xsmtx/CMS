<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Operations\OperationState;
use App\Domain\Operations\OperationType;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
final class OperationFactory extends Factory
{
    protected $model = Operation::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'type' => OperationType::ServiceProvision->value,
            'state' => OperationState::Pending->value,
            'attempt' => 0,
            'max_attempts' => 3,
            'progress' => 0,
            'needs_intervention' => false,
        ];
    }

    public function inState(OperationState $state): self
    {
        return $this->state(fn (): array => ['state' => $state->value]);
    }

    public function dueForRetry(): self
    {
        return $this->state(fn (): array => [
            'state' => OperationState::Retrying->value,
            'attempt' => 1,
            'next_attempt_at' => now()->subMinute(),
        ]);
    }

    public function needingIntervention(): self
    {
        return $this->state(fn (): array => [
            'state' => OperationState::ManualIntervention->value,
            'needs_intervention' => true,
            'error' => 'The losing registrar rejected the transfer',
        ]);
    }
}
