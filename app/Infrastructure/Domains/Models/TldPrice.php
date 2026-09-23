<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Models;

use App\Domain\Domains\DomainAction;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\TldPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the TLD price matrix.
 *
 * @property DomainAction $action
 * @property int $years
 * @property string $currency_code
 * @property Money $amount
 * @property Money|null $cost
 */
final class TldPrice extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TldPriceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'tld_prices';

    protected $fillable = [
        'organization_id',
        'tld_id',
        'action',
        'years',
        'currency_code',
        'amount_minor',
        'cost_minor',
    ];

    /**
     * @var array<string, int>
     */
    protected $attributes = ['amount_minor' => 0];

    /**
     * @return BelongsTo<Tld, $this>
     */
    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DomainAction::class,
            'years' => 'integer',
            'amount' => MoneyCast::class.':amount_minor',
            'cost' => MoneyCast::class.':cost_minor',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
