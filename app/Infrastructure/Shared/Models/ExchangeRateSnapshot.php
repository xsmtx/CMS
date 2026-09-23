<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ExchangeRateSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What a rate was, at a moment.
 *
 * Append-only for the same reason the audit trail is: a document issued last
 * March has to be able to say what the rate was in March, and a mutable rate
 * table cannot answer that.
 *
 * @property string $code
 * @property string $rate
 * @property string|null $source
 * @property CarbonImmutable $captured_at
 */
final class ExchangeRateSnapshot extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ExchangeRateSnapshotFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'exchange_rate_snapshots';

    protected $guarded = [];

    /**
     * @return BelongsTo<CurrencyRecord, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(CurrencyRecord::class, 'currency_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new RuntimeException('Exchange rate snapshots are append-only and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Exchange rate snapshots are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'string',
            'captured_at' => 'immutable_datetime',
        ];
    }
}
