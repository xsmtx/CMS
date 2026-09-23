<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing that was bought, as it was described at the time.
 *
 * `name` is a copy, not a lookup. The `product_id` beside it is for
 * reporting and is nulled rather than cascaded when a product is deleted,
 * because losing the product must not lose the record of its sales.
 *
 * @property LineKind $kind
 * @property string $name
 * @property BillingCycle|null $billing_cycle
 * @property int $quantity
 * @property Money $unit_recurring
 * @property Money $unit_setup
 * @property Money $line_recurring
 * @property Money $line_setup
 * @property Money $line_discount
 * @property Money $line_total
 * @property string|null $domain_action
 * @property list<string>|null $domain_addons
 * @property int|null $price_override_minor
 */
final class OrderItem extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'order_items';

    protected $fillable = [
        'organization_id',
        'order_id',
        'parent_id',
        'kind',
        'product_id',
        'addon_id',
        'name',
        'group_name',
        'description',
        'billing_cycle',
        'quantity',
        'currency_code',
        'unit_recurring_minor',
        'unit_setup_minor',
        'line_recurring_minor',
        'line_setup_minor',
        'line_discount_minor',
        'line_total_minor',
        'domain',
        'domain_tld',
        'domain_years',
        'domain_action',
        'domain_addons',
        'price_override_minor',
        'position',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * @return HasMany<OrderItemOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
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
            'unit_recurring' => MoneyCast::class.':unit_recurring_minor,currency_code',
            'unit_setup' => MoneyCast::class.':unit_setup_minor,currency_code',
            'line_recurring' => MoneyCast::class.':line_recurring_minor,currency_code',
            'line_setup' => MoneyCast::class.':line_setup_minor,currency_code',
            'line_discount' => MoneyCast::class.':line_discount_minor,currency_code',
            'line_total' => MoneyCast::class.':line_total_minor,currency_code',
            'unit_recurring_minor' => 'integer',
            'unit_setup_minor' => 'integer',
            'line_recurring_minor' => 'integer',
            'line_setup_minor' => 'integer',
            'line_discount_minor' => 'integer',
            'line_total_minor' => 'integer',
            'domain_years' => 'integer',
            'domain_addons' => 'array',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
