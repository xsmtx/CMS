<?php

declare(strict_types=1);

namespace App\Infrastructure\Promotions\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Promotions\PromotionApplication;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code.
 *
 * Whether a code applies is decided by the promotion engine, not here: this
 * model holds the terms, and one place decides what they mean so the cart
 * and the order cannot disagree about them.
 *
 * @property string $code
 * @property PromotionType $type
 * @property Money|null $amount
 * @property string|null $percentage
 * @property PromotionScope $scope
 * @property PromotionApplication $application
 * @property list<string>|null $billing_cycles
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property int|null $per_customer_limit
 * @property Money|null $minimum_subtotal
 * @property bool $new_customers_only
 * @property bool $stackable
 * @property bool $is_active
 */
final class Promotion extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'promotions';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'type',
        'amount_minor',
        'currency_code',
        'percentage',
        'scope',
        'application',
        'billing_cycles',
        'starts_at',
        'ends_at',
        'usage_limit',
        'per_customer_limit',
        'minimum_subtotal_minor',
        'new_customers_only',
        'stackable',
        'is_active',
    ];

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * Whether the code is good for this billing cycle. A null or empty list
     * means every cycle; an empty list meaning "none" would make a code
     * nobody can use.
     */
    public function coversCycle(BillingCycle $cycle): bool
    {
        $cycles = $this->billing_cycles;

        return $cycles === null || $cycles === [] || in_array($cycle->value, $cycles, strict: true);
    }

    public function coversProduct(?string $productId): bool
    {
        if ($this->scope !== PromotionScope::Products) {
            return true;
        }

        if ($productId === null) {
            return false;
        }

        return $this->products()->whereKey($productId)->exists();
    }

    public function auditLabel(): string
    {
        return $this->code;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        // Customers type codes in whatever case they like. Storing them
        // upper-cased keeps the lookup a plain equality on an indexed
        // column rather than a function over it.
        self::saving(static function (self $promotion): void {
            $promotion->code = strtoupper(trim($promotion->code));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'scope' => PromotionScope::class,
            'application' => PromotionApplication::class,
            'amount' => MoneyCast::class.':amount_minor,currency_code',
            'minimum_subtotal' => MoneyCast::class.':minimum_subtotal_minor,currency_code',
            'amount_minor' => 'integer',
            'minimum_subtotal_minor' => 'integer',
            // A decimal string, never a float.
            'percentage' => 'string',
            'billing_cycles' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'usage_limit' => 'integer',
            'usage_count' => 'integer',
            'per_customer_limit' => 'integer',
            'new_customers_only' => 'boolean',
            'stackable' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
