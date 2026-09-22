<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\ProductType;
use App\Domain\Provisioning\AutoSetup;
use App\Infrastructure\Catalog\Concerns\HasPrices;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Something a customer can buy.
 *
 * @property string $id
 * @property string $product_group_id
 * @property string $name
 * @property string $slug
 * @property ProductType $type
 * @property string|null $tagline
 * @property string|null $description
 * @property list<string>|null $features
 * @property CatalogStatus $status
 * @property int $position
 * @property int|null $stock
 * @property bool $requires_domain
 * @property string|null $provisioning_module
 * @property string|null $provisioning_package
 * @property AutoSetup $auto_setup
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Product extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasPrices;
    use HasUlids;

    protected $table = 'products';

    protected $fillable = [
        'organization_id',
        'product_group_id',
        'name',
        'slug',
        'type',
        'tagline',
        'description',
        'features',
        'status',
        'position',
        'stock',
        'requires_domain',
        'provisioning_module',
        'server_group_id',
        'provisioning_package',
        'auto_setup',
    ];

    /**
     * @return BelongsTo<ProductGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * @return HasMany<OptionGroup, $this>
     */
    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class)->orderBy('position')->orderBy('name');
    }

    /**
     * @return HasMany<Addon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class)->orderBy('position')->orderBy('name');
    }

    /**
     * Whether a new order may be placed.
     *
     * Stock of null is unlimited; zero is sold out. A boolean would not be
     * able to tell those apart.
     */
    public function isOrderable(): bool
    {
        if (! $this->status->isOrderable()) {
            return false;
        }

        return $this->stock === null || $this->stock > 0;
    }

    public function isSoldOut(): bool
    {
        return $this->stock !== null && $this->stock === 0;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Where a service bought from this product is placed.
     *
     * @return BelongsTo<ServerGroup, $this>
     */
    public function serverGroup(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'server_group_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeListed(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    /**
     * Items that can still be reached, including hidden ones. Used by the
     * storefront's direct-link lookup, where hidden means "orderable by
     * someone who has the URL".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeOrderable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CatalogStatus::Active->value,
            CatalogStatus::Hidden->value,
        ]);
    }

    protected static function booted(): void
    {
        self::saving(static function (self $product): void {
            if ($product->slug === '' || $product->slug === null) {
                $product->slug = Str::slug($product->name);
            }

            // The type decides whether an order collects a hostname. It is
            // still overridable, because an operator may sell a VPS bundled
            // with a domain.
            if (! $product->isDirty('requires_domain')) {
                $product->requires_domain = $product->type->requiresDomain();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => CatalogStatus::class,
            'features' => 'array',
            'position' => 'integer',
            'stock' => 'integer',
            'requires_domain' => 'boolean',
            'auto_setup' => AutoSetup::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
