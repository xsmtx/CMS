<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\BillableItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off charge waiting for the next invoice.
 *
 * The same shape `usage_snapshots` has, for the same reason: an invoice is
 * frozen at issue (ADR 0023), so a charge is **quoted** by a line and the row
 * is stamped with that line — which is what makes it impossible to charge
 * twice, and what makes it safe inside a run that may be retried.
 *
 * `unit_amount_minor` is **signed**, which `Money` handles and most of this
 * product's money columns do not. A negotiated reduction is a one-off charge
 * of a negative amount; a credit note is the wrong document for something that
 * has not been invoiced yet.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string|null $service_id
 * @property string $description
 * @property int $quantity
 * @property string $currency_code
 * @property int $unit_amount_minor
 * @property CarbonImmutable|null $charge_on
 * @property string|null $invoice_item_id
 * @property CarbonImmutable|null $charged_at
 * @property string|null $created_by
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BillableItem extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<BillableItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'billable_items';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'service_id',
        'description',
        'quantity',
        'currency_code',
        'unit_amount_minor',
        'charge_on',
        'invoice_item_id',
        'charged_at',
        'created_by',
        'note',
    ];

    /**
     * The database defaults, declared again — a default fills the row and
     * leaves the model in memory without the attribute, which a cast then
     * reads as null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'unit_amount_minor' => 0,
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function unitPrice(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency_code);
    }

    /**
     * Quantity times the unit price, which is what a line charges.
     *
     * Multiplied here rather than stored, because a quantity an operator
     * edits and a total column would disagree the first time somebody
     * corrected one.
     */
    public function total(): Money
    {
        return Money::ofMinor($this->unit_amount_minor * $this->quantity, $this->currency_code);
    }

    public function isCharged(): bool
    {
        return $this->charged_at !== null;
    }

    public function auditLabel(): string
    {
        return $this->description;
    }

    /**
     * Uncharged, and due — `charge_on` is "not before", so null is now.
     *
     * A scope rather than a condition at each call site, because there are
     * two call sites and the null case is the one somebody writing it by hand
     * gets backwards.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    protected function scopeChargeable(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $today = ($now ?? CarbonImmutable::now())->toDateString();

        return $query
            ->whereNull('charged_at')
            ->where(static fn (Builder $inner): Builder => $inner
                ->whereNull('charge_on')
                ->orWhere('charge_on', '<=', $today));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'charge_on' => 'immutable_date',
            'charged_at' => 'immutable_datetime',
        ];
    }
}
