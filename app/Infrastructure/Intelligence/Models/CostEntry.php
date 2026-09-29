<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence\Models;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Intelligence\AllocationStrategy;
use App\Domain\Intelligence\CostPeriod;
use App\Domain\Intelligence\CostScope;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\CostEntryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What the provider pays somebody else, stated by an operator (§21).
 *
 * Not a transaction and never on the ledger: the ledger is what customers
 * paid this provider (ADR 0024), and this is the other side of the business.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $label
 * @property string|null $vendor
 * @property CostScope $scope
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $currency_code
 * @property int $amount_minor
 * @property Money $amount
 * @property CostPeriod $period
 * @property AllocationStrategy $strategy
 * @property MetricKind|null $metric
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class CostEntry extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CostEntryFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'label',
        'vendor',
        'scope',
        'subject_type',
        'subject_id',
        'currency_code',
        'amount_minor',
        'period',
        'strategy',
        'metric',
        'starts_on',
        'ends_on',
        'note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'amount_minor' => 0,
        'scope' => 'server',
        'period' => 'monthly',
        'strategy' => 'even',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this cost applies in the month beginning on a date.
     *
     * A server bought in June did not cost anything in May, and a contract
     * that ended in September must stop appearing in October. A one-off is
     * charged in the month it starts and in no other, which is what keeps a
     * migration paid for once in March out of the other eleven months.
     */
    public function appliesIn(CarbonImmutable $monthStart): bool
    {
        $monthEnd = $monthStart->endOfMonth();

        if ($this->starts_on !== null && $this->starts_on->greaterThan($monthEnd)) {
            return false;
        }

        if ($this->ends_on !== null && $this->ends_on->lessThan($monthStart)) {
            return false;
        }

        if ($this->period !== CostPeriod::OneOff) {
            return true;
        }

        // A one-off with no date is this month's, because there is nothing
        // else it could be — and saying so is better than silently never
        // counting it.
        $on = $this->starts_on ?? $monthStart;

        return $on->betweenIncluded($monthStart, $monthEnd);
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    /**
     * What this costs in the month, never a float.
     *
     * @return Attribute<Money, never>
     */
    protected function amount(): Attribute
    {
        return Attribute::get(fn (): Money => Money::ofMinor($this->amount_minor, $this->currency_code));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CostScope::class,
            'period' => CostPeriod::class,
            'strategy' => AllocationStrategy::class,
            'metric' => MetricKind::class,
            'amount_minor' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
