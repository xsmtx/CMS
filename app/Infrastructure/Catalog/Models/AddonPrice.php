<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\AddonPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price for one addon, on one billing cycle, in one currency.
 *
 * @property BillingCycle $billing_cycle
 * @property string $currency_code
 * @property Money|null $recurring
 * @property Money|null $setup
 */
final class AddonPrice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AddonPriceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'addon_prices';

    protected $fillable = [
        'organization_id',
        'addon_id',
        'billing_cycle',
        'currency_code',
        'recurring_minor',
        'setup_minor',
    ];

    /**
     * @return BelongsTo<Addon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
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
