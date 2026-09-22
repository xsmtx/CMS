<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\NumberSequence;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
final class NumberSequenceFactory extends Factory
{
    protected $model = NumberSequence::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => $this->owningOrganization()->id,
            'key' => 'order',
            'prefix' => 'ORD-',
            'next_value' => 1,
            'padding' => 6,
        ];
    }

    private function owningOrganization(): Organization
    {
        $boundary = app(OrganizationContext::class)->id();

        if ($boundary !== null) {
            return Organization::query()->withoutGlobalScope('organization')->findOrFail($boundary);
        }

        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first() ?? Organization::factory()->provider()->create();
    }
}
