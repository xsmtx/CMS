<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Models;

use App\Domain\Shared\Currency;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\CurrencyRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A currency an installation sells in.
 *
 * Named `CurrencyRecord` rather than `Currency` because the domain value
 * object already owns that name, and the two are genuinely different things:
 * the value object is ISO 4217, this row is an operator's decision to trade
 * in it at a particular rate.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $symbol
 * @property int $exponent
 * @property string $rate
 * @property bool $is_base
 * @property bool $is_active
 */
final class CurrencyRecord extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CurrencyRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'currencies';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'symbol',
        'exponent',
        'rate',
        'is_base',
        'is_active',
    ];

    /**
     * @return HasMany<ExchangeRateSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ExchangeRateSnapshot::class, 'currency_id')->latest('captured_at');
    }

    /**
     * The ISO definition behind this row.
     */
    public function currency(): Currency
    {
        return Currency::of($this->code);
    }

    public function auditLabel(): string
    {
        return $this->code.' ('.$this->name.')';
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
        self::saving(static function (self $record): void {
            $record->code = strtoupper($record->code);

            // The exponent is not an operator's to invent: it comes from the
            // ISO definition, and a wrong one silently changes every price.
            $record->exponent = Currency::of($record->code)->exponent;

            // The base currency is the unit everything else is quoted in, so
            // its own rate is 1 by definition.
            if ($record->is_base) {
                $record->rate = '1.00000000';
            }
        });

        // Exactly one base per organization.
        $demoteSiblings = static function (self $record): void {
            if (! $record->is_base) {
                return;
            }

            self::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $record->organization_id)
                ->whereKeyNot($record->getKey())
                ->update(['is_base' => false]);
        };

        self::created($demoteSiblings);
        self::updated($demoteSiblings);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exponent' => 'integer',
            // A string, never a float: the rate is decimal data and binary
            // floating point cannot hold it exactly.
            'rate' => 'string',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
