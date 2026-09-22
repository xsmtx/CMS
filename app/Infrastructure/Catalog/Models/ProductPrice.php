<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\ProductPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price: a product, on one billing cycle, in one currency.
 *
 * @property string $id
 * @property BillingCycle $billing_cycle
 * @property string $currency_code
 * @property Money|null $recurring
 * @property Money|null $setup
 */
final class ProductPrice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'product_prices';

    protected $fillable = [
        'organization_id',
        'product_id',
        'billing_cycle',
        'currency_code',
        'recurring_minor',
        'setup_minor',
    ];

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
