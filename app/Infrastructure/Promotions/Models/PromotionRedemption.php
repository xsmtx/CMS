<?php

declare(strict_types=1);

namespace App\Infrastructure\Promotions\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionRedemptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One use of a code.
 *
 * Append-only for the same reason the audit trail is: what a promotion cost
 * has to stay readable after the order is cancelled and after the promotion
 * itself is edited.
 *
 * @property Money $amount
 * @property CarbonImmutable $redeemed_at
 */
final class PromotionRedemption extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PromotionRedemptionFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'promotion_redemptions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new RuntimeException('Promotion redemptions are append-only and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Promotion redemptions are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency_code',
            'amount_minor' => 'integer',
            'redeemed_at' => 'immutable_datetime',
        ];
    }
}
