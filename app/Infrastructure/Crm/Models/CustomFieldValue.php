<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\CustomFieldValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One stored value for one definition on one record.
 *
 * @property string $id
 * @property string $definition_id
 * @property mixed $value
 */
final class CustomFieldValue extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CustomFieldValueFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'custom_field_values';

    protected $fillable = ['organization_id', 'definition_id', 'value'];

    /**
     * @return BelongsTo<CustomFieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'definition_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
