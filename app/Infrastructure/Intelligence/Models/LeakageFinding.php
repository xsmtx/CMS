<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence\Models;

use App\Domain\Intelligence\LeakageKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\LeakageFindingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One way money quietly stopped arriving (§21).
 *
 * Raised once, kept while it stays true, cleared rather than deleted — the
 * shape `alerts`, `zone_findings` and `reconciliation_findings` share. The
 * cleared row is what answers "we fixed that, when?", which is the question
 * asked the next time the figure moves.
 *
 * @property string $id
 * @property string $organization_id
 * @property LeakageKind $kind
 * @property string $subject_type
 * @property string $subject_id
 * @property string $subject_label
 * @property string|null $customer_id
 * @property string|null $customer_label
 * @property string $currency_code
 * @property int $amount_minor
 * @property Money $amount
 * @property array<string, mixed>|null $detail
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $cleared_at
 * @property string $cleared_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LeakageFinding extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<LeakageFindingFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'kind',
        'subject_type',
        'subject_id',
        'subject_label',
        'customer_id',
        'customer_label',
        'currency_code',
        'amount_minor',
        'detail',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'cleared_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cleared_token' => '',
        'amount_minor' => 0,
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function auditLabel(): string
    {
        return $this->subject_label;
    }

    /**
     * What is at stake, never a float.
     *
     * @return Attribute<Money, never>
     */
    protected function amount(): Attribute
    {
        return Attribute::get(fn (): Money => Money::ofMinor($this->amount_minor, $this->currency_code));
    }

    /**
     * @param  Builder<LeakageFinding>  $query
     * @return Builder<LeakageFinding>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LeakageKind::class,
            'detail' => 'array',
            'amount_minor' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
