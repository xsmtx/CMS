<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Option>
 */
final class OptionFactory extends Factory
{
    protected $model = Option::class;

    public function definition(): array
    {
        $label = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'option_group_id' => fn (): string => OptionGroup::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['option_group_id']),
            'label' => $label,
            'value' => Str::slug($label, '_'),
            'position' => 0,
            'is_default' => false,
        ];
    }

    public function inGroup(OptionGroup|string $group): static
    {
        $id = $group instanceof OptionGroup ? $group->id : $group;

        return $this->state(fn (): array => [
            'option_group_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    public function isDefault(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    private function organizationOf(string $id): string
    {
        return OptionGroup::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
