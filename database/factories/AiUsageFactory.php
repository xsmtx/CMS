<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ai\AiFeature;
use App\Infrastructure\Ai\Models\AiUsage;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
final class AiUsageFactory extends Factory
{
    protected $model = AiUsage::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'feature' => AiFeature::TicketReply->value,
            'provider_key' => 'fake',
            'model' => 'fake-1',
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'outcome' => AiUsage::OutcomeAnswered,
        ];
    }

    public function refused(): self
    {
        return $this->state(fn (): array => [
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'outcome' => AiUsage::OutcomeRefused,
        ]);
    }
}
