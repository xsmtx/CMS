<?php

declare(strict_types=1);

namespace App\Infrastructure\Resellers\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\ResellerPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exact price one reseller charges for one product, cycle and currency.
 *
 * It beats any margin, because an operator who typed a number meant that
 * number. A row exists only where somebody set one — the same rule the
 * provider's matrix follows, where absence means "not priced this way"
 * rather than zero.
 *
 * @property BillingCycle $billing_cycle
 * @property Money $recurring
 * @property Money $setup
 */
final class ResellerPrice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ResellerPriceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'reseller_prices';

    protected $fillable = [
        'organization_id',
        'product_id',
        'billing_cycle',
        'currency_code',
        'recurring_minor',
        'setup_minor',
    ];

    /** @var array<string, int> */
    protected $attributes = ['setup_minor' => 0];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'recurring' => MoneyCast::class.':recurring_minor,currency_code',
            'setup' => MoneyCast::class.':setup_minor,currency_code',
            'recurring_minor' => 'integer',
            'setup_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
