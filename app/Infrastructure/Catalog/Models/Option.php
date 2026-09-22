<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Infrastructure\Catalog\Concerns\HasPrices;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\OptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One choice within an option group, with its own price deltas.
 *
 * @property string $id
 * @property string $option_group_id
 * @property string $label
 * @property string $value
 * @property int $position
 * @property bool $is_default
 */
final class Option extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<OptionFactory> */
    use HasFactory;

    use HasPrices;
    use HasUlids;

    protected $table = 'options';

    protected $fillable = [
        'organization_id',
        'option_group_id',
        'label',
        'value',
        'position',
        'is_default',
    ];

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    /**
     * @return HasMany<OptionPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(OptionPrice::class);
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    protected static function booted(): void
    {
        self::saving(static function (self $option): void {
            if ($option->value === '' || $option->value === null) {
                $option->value = Str::slug($option->label, '_');
            }
        });

        // Exactly one default per group, enforced here so that no screen can
        // create two and leave the order form guessing which to preselect.
        $demoteSiblings = static function (self $option): void {
            if (! $option->is_default) {
                return;
            }

            self::query()
                ->where('option_group_id', $option->option_group_id)
                ->whereKeyNot($option->getKey())
                ->update(['is_default' => false]);
        };

        self::created($demoteSiblings);
        self::updated($demoteSiblings);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
