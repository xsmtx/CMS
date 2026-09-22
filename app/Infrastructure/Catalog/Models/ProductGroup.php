<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\CatalogStatus;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ProductGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A section of the storefront.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CatalogStatus $status
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ProductGroup extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ProductGroupFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'product_groups';

    protected $fillable = ['organization_id', 'name', 'slug', 'description', 'status', 'position'];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('position')->orderBy('name');
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
        self::saving(static function (self $group): void {
            if ($group->slug === '' || $group->slug === null) {
                $group->slug = Str::slug($group->name);
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
