<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * The shapes a custom field may take.
 *
 * Kept deliberately small. Every additional type is a validation rule, an
 * input component, an export format and a migration path, and operators who
 * need more than this need a module rather than a wider enum.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';

    public function labelKey(): string
    {
        return 'crm.custom_field_types.'.$this->value;
    }

    public function requiresOptions(): bool
    {
        return $this === self::Select;
    }

    /**
     * Validation rules applied to a submitted value, before the definition's
     * own required flag is considered.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:255'],
            self::Textarea => ['string', 'max:5000'],
            self::Number => ['numeric'],
            self::Boolean => ['boolean'],
            self::Date => ['date'],
            self::Select => ['string', 'max:255'],
        };
    }
}
