<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Concerns;

use App\Domain\Crm\CustomFieldEntity;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Crm\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * @mixin Model
 */
trait HasCustomFields
{
    abstract public function customFieldEntity(): CustomFieldEntity;

    /**
     * @return MorphMany<CustomFieldValue, $this>
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'owner');
    }

    /**
     * Definitions and their current values, in display order.
     *
     * Returns every definition, including ones with no value yet, so a form
     * renders the whole schema rather than only what happens to be filled.
     *
     * @return Collection<int, array{definition: CustomFieldDefinition, value: mixed}>
     */
    public function customFieldSchema(bool $customerVisibleOnly = false): Collection
    {
        $values = $this->customFieldValues()->get()->keyBy('definition_id');

        return CustomFieldDefinition::query()
            ->where('entity_type', $this->customFieldEntity()->value)
            ->when($customerVisibleOnly, fn ($query) => $query->where('is_customer_visible', true))
            ->orderBy('position')
            ->orderBy('label')
            ->get()
            ->map(fn (CustomFieldDefinition $definition): array => [
                'definition' => $definition,
                'value' => $values->get($definition->id)?->value,
            ])
            ->values();
    }
}
