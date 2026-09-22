<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\OptionPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price delta for one option, on one billing cycle, in one currency.
 *
 * Signed: an option may reduce the price as well as raise it, which is how a
 * "no control panel" choice is priced.
 *
 * @property BillingCycle $billing_cycle
 * @property string $currency_code
 * @property Money $recurring
 * @property Money $setup
 */
final class OptionPrice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<OptionPriceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'option_prices';

    protected $fillable = [
        'organization_id',
        'option_id',
        'billing_cycle',
        'currency_code',
        'recurring_minor',
        'setup_minor',
    ];

    /**
     * @return BelongsTo<Option, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
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
