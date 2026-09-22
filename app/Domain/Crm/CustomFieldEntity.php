<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Which records can carry custom fields. Later phases add their own members
 * rather than making this a free-form string, so a typo cannot silently
 * create an orphaned definition.
 */
enum CustomFieldEntity: string
{
    case Customer = 'customer';
    case Contact = 'contact';

    public function labelKey(): string
    {
        return 'crm.custom_field_entities.'.$this->value;
    }
}
