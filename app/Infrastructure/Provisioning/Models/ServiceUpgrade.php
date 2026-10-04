<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\UpgradeState;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceUpgradeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to move a service between plans.
 *
 * The amounts are frozen here: they were computed against the term the service
 * was in at the moment of asking, and that term moves. Recomputing them when
 * the upgrade is applied would charge a figure nobody agreed to.
 *
 * `difference_minor` is signed and `credit`/`charge` are not, which is the
 * shape the arithmetic has: both halves are amounts and only their difference
 * has a direction.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $service_id
 * @property UpgradeState $state
 * @property string|null $from_product_id
 * @property string $from_product_name
 * @property BillingCycle $from_cycle
 * @property Money $from_recurring
 * @property string $to_product_id
 * @property string $to_product_name
 * @property BillingCycle $to_cycle
 * @property Money $to_recurring
 * @property string $currency_code
 * @property Money $credit
 * @property Money $charge
 * @property int $difference_minor
 * @property int $days_remaining
 * @property int $term_days
 * @property bool $restarts_term
 * @property string|null $invoice_id
 * @property string|null $credit_note_id
 * @property string|null $requested_by_staff
 * @property string|null $requested_by_contact
 * @property string|null $note
 * @property string|null $result
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ServiceUpgrade extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ServiceUpgradeFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'service_upgrades';

    protected $fillable = [
        'organization_id',
        'service_id',
        'state',
        'from_product_id',
        'from_product_name',
        'from_cycle',
        'from_recurring_minor',
        'to_product_id',
        'to_product_name',
        'to_cycle',
        'to_recurring_minor',
        'currency_code',
        'credit_minor',
        'charge_minor',
        'difference_minor',
        'days_remaining',
        'term_days',
        'restarts_term',
        'invoice_id',
        'credit_note_id',
        'requested_by_staff',
        'requested_by_contact',
        'note',
        'result',
        'applied_at',
    ];

    /**
     * The database defaults, declared again — a default fills the row and
     * leaves the model in memory without the attribute, which a cast then
     * reads as null. It bit the money columns in Phase 4.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'awaiting_payment',
        'from_recurring_minor' => 0,
        'to_recurring_minor' => 0,
        'credit_minor' => 0,
        'charge_minor' => 0,
        'difference_minor' => 0,
        'days_remaining' => 0,
        'term_days' => 0,
        'restarts_term' => false,
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * What the customer owes, with its direction.
     *
     * A method rather than a cast, because `difference_minor` is the one
     * signed column here and `MoneyCast` reads an unsigned pair.
     */
    public function difference(): Money
    {
        return Money::ofMinor($this->difference_minor, $this->currency_code);
    }

    public function isDowngrade(): bool
    {
        return $this->difference_minor < 0;
    }

    public function auditLabel(): string
    {
        return $this->from_product_name.' → '.$this->to_product_name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => UpgradeState::class,
            'from_cycle' => BillingCycle::class,
            'to_cycle' => BillingCycle::class,
            'from_recurring' => MoneyCast::class.':from_recurring_minor,currency_code',
            'to_recurring' => MoneyCast::class.':to_recurring_minor,currency_code',
            'credit' => MoneyCast::class.':credit_minor,currency_code',
            'charge' => MoneyCast::class.':charge_minor,currency_code',
            'difference_minor' => 'integer',
            'days_remaining' => 'integer',
            'term_days' => 'integer',
            'restarts_term' => 'boolean',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
