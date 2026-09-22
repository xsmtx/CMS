<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\CustomFieldEntity;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Crm\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes custom field values for a record.
 *
 * Only definitions that exist for the entity are written, and a value the
 * caller is not allowed to set is ignored rather than rejected: a staff form
 * and a customer form post different subsets of the same schema.
 */
final readonly class SaveCustomFieldValues
{
    /**
     * @param  array<string, mixed>  $values  Keyed by definition key.
     */
    public function handle(
        Model $owner,
        CustomFieldEntity $entity,
        array $values,
        bool $customerEditableOnly = false,
    ): void {
        if ($values === []) {
            return;
        }

        $definitions = CustomFieldDefinition::query()
            ->where('entity_type', $entity->value)
            ->when($customerEditableOnly, fn ($query) => $query->where('is_customer_editable', true))
            ->whereIn('key', array_keys($values))
            ->get();

        foreach ($definitions as $definition) {
            CustomFieldValue::query()->updateOrCreate(
                [
                    'definition_id' => $definition->id,
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => (string) $owner->getKey(),
                ],
                [
                    'organization_id' => $owner->getAttribute('organization_id'),
                    'value' => $values[$definition->key] ?? null,
                ],
            );
        }
    }
}
