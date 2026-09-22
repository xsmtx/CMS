<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a cart: a product with its choices, an addon bought with it,
 * or a domain.
 *
 * @property LineKind $kind
 * @property BillingCycle|null $billing_cycle
 * @property int $quantity
 * @property string|null $domain
 * @property int|null $domain_years
 */
final class CartItem extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'cart_items';

    protected $fillable = [
        'organization_id',
        'cart_id',
        'parent_id',
        'kind',
        'product_id',
        'addon_id',
        'billing_cycle',
        'quantity',
        'domain',
        'domain_tld',
        'domain_years',
        'domain_registration_minor',
        'position',
    ];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Addon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    /**
     * @return HasMany<CartItemOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(CartItemOption::class);
    }

    /**
     * Addon lines bought with this product line.
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * A domain's price comes from outside the catalog, so unlike every
     * other line it carries its own quote.
     *
     * The currency is passed in rather than read back through the cart: the
     * caller already holds it, and a line whose cart failed to load should
     * not silently price in nothing.
     */
    public function domainRegistration(string $currencyCode): ?Money
    {
        $minor = $this->getAttribute('domain_registration_minor');

        return $minor === null ? null : Money::ofMinor((int) $minor, $currencyCode);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LineKind::class,
            'billing_cycle' => BillingCycle::class,
            'quantity' => 'integer',
            'domain_years' => 'integer',
            'domain_registration_minor' => 'integer',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
