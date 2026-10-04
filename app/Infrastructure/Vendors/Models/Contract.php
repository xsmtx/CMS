<?php

declare(strict_types=1);

namespace App\Infrastructure\Vendors\Models;

use App\Domain\Shared\Money;
use App\Domain\Vendors\ContractTerm;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was agreed with a vendor (§24).
 *
 * **Not a cost entry, and it must not become one.** A cost entry is what a
 * month was charged; a contract is what was agreed. The same money in both,
 * as two rows nobody reconciles, is the figure somebody would quote — so
 * `cost_entries.contract_id` is a pointer and the amount is never copied.
 *
 * `ends_on` is nullable and the null is a real answer: a rolling agreement
 * with no end date exists, and inventing one would put a date on the expiry
 * list that nobody agreed to. A contract with no end simply never appears
 * there, which is the truth.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $vendor_id
 * @property string $title
 * @property string|null $reference
 * @property ContractTerm $term
 * @property string $currency_code
 * @property int $amount_minor
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property bool $auto_renews
 * @property int|null $notice_days
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Contract extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'contracts';

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'title',
        'reference',
        'term',
        'currency_code',
        'amount_minor',
        'starts_on',
        'ends_on',
        'auto_renews',
        'notice_days',
        'note',
    ];

    /**
     * The database defaults, declared again.
     *
     * A default fills the row and leaves the model in memory without the
     * attribute, and a cast reads that absence as null. It bit the money
     * columns in Phase 4 and the booleans in Phase 7.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount_minor' => 0,
        'auto_renews' => false,
    ];

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function price(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency_code);
    }

    /**
     * The day somebody has to have decided by.
     *
     * On an auto-renewing contract the date that matters is not the end: it
     * is the last day to give notice, and a list that showed the end date
     * would be showing somebody a deadline they had already missed.
     */
    public function decideBy(): ?CarbonImmutable
    {
        if ($this->ends_on === null) {
            return null;
        }

        return $this->notice_days === null
            ? $this->ends_on
            : $this->ends_on->subDays($this->notice_days);
    }

    /**
     * Days until the decision, which may be negative.
     *
     * Negative on purpose and all the way through: a contract whose notice
     * period closed last night is the one somebody most needs to hear about,
     * and clamping it at zero would hide exactly that.
     */
    public function daysRemaining(?CarbonImmutable $now = null): ?int
    {
        $deadline = $this->decideBy();

        if ($deadline === null) {
            return null;
        }

        return (int) ($now ?? CarbonImmutable::now())->startOfDay()->diffInDays($deadline->startOfDay(), false);
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    protected function scopeEnding(Builder $query): Builder
    {
        return $query->whereNotNull('ends_on')->oldest('ends_on');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term' => ContractTerm::class,
            'amount_minor' => 'integer',
            'notice_days' => 'integer',
            'auto_renews' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }
}
