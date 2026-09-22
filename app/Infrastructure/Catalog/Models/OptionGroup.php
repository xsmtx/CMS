<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\OptionType;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A configurable choice on a product: disk size, control panel, extra IPs.
 *
 * @property string $id
 * @property string $product_id
 * @property string $name
 * @property string $key
 * @property OptionType $type
 * @property bool $is_required
 * @property int $min_quantity
 * @property int|null $max_quantity
 * @property int $position
 */
final class OptionGroup extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<OptionGroupFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'option_groups';

    protected $fillable = [
        'organization_id',
        'product_id',
        'name',
        'key',
        'type',
        'description',
        'is_required',
        'min_quantity',
        'max_quantity',
        'position',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<Option, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('position')->orderBy('label');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    protected static function booted(): void
    {
        self::saving(static function (self $group): void {
            if ($group->key === '' || $group->key === null) {
                $group->key = Str::slug($group->name, '_');
            }

            // A quantity option has exactly one option row whose price is
            // multiplied, so the bounds only mean anything there.
            if (! $group->type->isQuantity()) {
                $group->min_quantity = 0;
                $group->max_quantity = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OptionType::class,
            'is_required' => 'boolean',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
