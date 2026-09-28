<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Billing\UsageUnit;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\UsageMeterRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one service is metered on, and what it costs (§25).
 *
 * Named `UsageMeterRecord` because `UsageMeter` is the contract a module
 * implements. The row and the adapter are different things and one of them is
 * public API (ADR 0039).
 *
 * The price is here rather than in the catalog because a usage rate has three
 * dimensions the product price matrix has not: per service, per meter, with
 * an allowance. Two customers on one product routinely have different
 * allowances.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $service_id
 * @property string $source
 * @property string $meter_key
 * @property string $service_key
 * @property UsageUnit $unit
 * @property string $included_quantity
 * @property int $rate_minor
 * @property string $currency_code
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UsageMeterRecord extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<UsageMeterRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'usage_meters';

    protected $fillable = [
        'organization_id',
        'service_id',
        'source',
        'meter_key',
        'service_key',
        'unit',
        'included_quantity',
        'rate_minor',
        'currency_code',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'included_quantity' => 0,
        'rate_minor' => 0,
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<UsageSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(UsageSnapshot::class, 'usage_meter_id');
    }

    /** What one unit costs. */
    public function rate(): Money
    {
        return Money::ofMinor($this->rate_minor, $this->currency_code);
    }

    public function included(): float
    {
        return (float) $this->included_quantity;
    }

    /**
     * What a quantity costs, above the allowance.
     *
     * **Rounded once, at the line**, rather than per unit: rounding a rate
     * before multiplying is how a hundred gigabytes at a third of a penny
     * becomes either nothing or a pound. Half-up, because that is what a
     * customer reading the arithmetic expects, and the result is integer
     * minor units like every other amount in this product.
     */
    public function charge(float $quantity): Money
    {
        $billable = max(0.0, $quantity - $this->included());

        return Money::ofMinor((int) round($billable * $this->rate_minor), $this->currency_code);
    }

    public function auditLabel(): string
    {
        return $this->meter_key;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => UsageUnit::class,
            'rate_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
