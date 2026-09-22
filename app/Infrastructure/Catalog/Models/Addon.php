<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\CatalogStatus;
use App\Infrastructure\Catalog\Concerns\HasPrices;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\AddonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Something bought alongside a product, with its own price matrix.
 *
 * Distinct from an option: an option changes what the product is, an addon
 * is a separate line that can be added and removed later.
 *
 * @property string $id
 * @property string $product_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CatalogStatus $status
 * @property int $position
 */
final class Addon extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AddonFactory> */
    use HasFactory;

    use HasPrices;
    use HasUlids;

    protected $table = 'addons';

    protected $fillable = [
        'organization_id',
        'product_id',
        'name',
        'slug',
        'description',
        'status',
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
     * @return HasMany<AddonPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(AddonPrice::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeListed(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    protected static function booted(): void
    {
        self::saving(static function (self $addon): void {
            if ($addon->slug === '' || $addon->slug === null) {
                $addon->slug = Str::slug($addon->name);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
