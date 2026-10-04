<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ai\AiFeature;
use App\Infrastructure\Ai\Models\AiSetting;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSetting>
 */
final class AiSettingFactory extends Factory
{
    protected $model = AiSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            // Off, like a real installation nobody has configured. A factory
            // that built a working assistant would make every test agree that
            // the default is on.
            'provider_key' => null,
            'enabled_features' => [],
        ];
    }

    /**
     * @param  list<AiFeature>  $features
     */
    public function using(string $providerKey, array $features): self
    {
        return $this->state(fn (): array => [
            'provider_key' => $providerKey,
            'enabled_features' => array_map(
                static fn (AiFeature $feature): string => $feature->value,
                $features,
            ),
        ]);
    }
}
