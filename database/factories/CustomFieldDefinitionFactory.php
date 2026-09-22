<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Crm\CustomFieldType;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomFieldDefinition>
 */
final class CustomFieldDefinitionFactory extends Factory
{
    protected $model = CustomFieldDefinition::class;

    public function definition(): array
    {
        $label = Str::title(fake()->unique()->words(2, true));

        return [
            'entity_type' => CustomFieldEntity::Customer->value,
            'key' => Str::slug($label, '_'),
            'label' => $label,
            'type' => CustomFieldType::Text->value,
            'options' => null,
            'is_required' => false,
            'is_customer_visible' => false,
            'is_customer_editable' => false,
            'position' => 0,
        ];
    }

    public function ofType(CustomFieldType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function customerVisible(bool $editable = false): static
    {
        return $this->state(fn (): array => [
            'is_customer_visible' => true,
            'is_customer_editable' => $editable,
        ]);
    }
}
