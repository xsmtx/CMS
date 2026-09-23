<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Crm\CustomFieldType;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\CustomFieldDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An operator-defined field on a CRM record.
 *
 * The definition owns the type, so values can share one JSON column instead
 * of a column per type, and validation is derived rather than duplicated
 * into every form that touches the record.
 *
 * @property string $id
 * @property CustomFieldEntity $entity_type
 * @property string $key
 * @property string $label
 * @property CustomFieldType $type
 * @property list<string>|null $options
 * @property bool $is_required
 * @property bool $is_customer_visible
 * @property bool $is_customer_editable
 * @property int $position
 */
final class CustomFieldDefinition extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CustomFieldDefinitionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'custom_field_definitions';

    protected $fillable = [
        'organization_id',
        'entity_type',
        'key',
        'label',
        'type',
        'options',
        'help_text',
        'is_required',
        'is_customer_visible',
        'is_customer_editable',
        'position',
    ];

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'definition_id');
    }

    /**
     * Validation rules for a submitted value, combining the type's own rules
     * with the required flag and, for a select, the permitted options.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable', ...$this->type->validationRules()];

        if ($this->type === CustomFieldType::Select && $this->options !== null) {
            $rules[] = 'in:'.implode(',', $this->options);
        }

        return $rules;
    }

    public function auditLabel(): string
    {
        return $this->label.' ('.$this->key.')';
    }

    protected static function booted(): void
    {
        self::saving(static function (self $definition): void {
            if ($definition->key === '' || $definition->key === null) {
                $definition->key = Str::slug($definition->label, '_');
            }

            // A field the customer may edit but cannot see is not a state
            // any UI can render, so the stricter flag wins.
            if ($definition->is_customer_editable && ! $definition->is_customer_visible) {
                $definition->is_customer_editable = false;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => CustomFieldEntity::class,
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_customer_visible' => 'boolean',
            'is_customer_editable' => 'boolean',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
