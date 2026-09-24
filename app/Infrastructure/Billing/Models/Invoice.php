<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill.
 *
 * Draft is the only editable state. Once issued, the bill-to party and
 * every amount are frozen, because an invoice is a tax document and a
 * document whose numbers move is not evidence of anything. Corrections
 * happen through a credit note.
 *
 * @property string $number
 * @property InvoiceStatus $status
 * @property string $currency_code
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $tax
 * @property Money $total
 * @property Money $paid
 * @property array<int, array<string, mixed>>|null $tax_breakdown
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $last_capture_at
 * @property string|null $last_capture_outcome
 * @property bool $is_proforma
 * @property bool $is_late_fee
 */
final class Invoice extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'invoices';

    protected $fillable = [
        'organization_id',
        'number',
        'customer_id',
        'order_id',
        'status',
        'currency_code',
        'bill_to_name',
        'bill_to_company',
        'bill_to_tax_id',
        'bill_to_address',
        'bill_to_country',
        'bill_to_email',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'paid_minor',
        'tax_breakdown',
        'tax_exemption_reason',
        'issued_on',
        'due_on',
        'paid_at',
        'last_capture_at',
        'last_capture_outcome',
        'cancelled_at',
        'is_proforma',
        'is_late_fee',
        'notes',
        'terms',
    ];

    /**
     * The amounts a row always has.
     *
     * Declared here rather than relying on the column defaults, because a
     * default fills the row and leaves the model in memory without the
     * attribute — and a money cast reads that absence as null.
     *
     * @var array<string, int|bool>
     */
    protected $attributes = [
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
        'paid_minor' => 0,
        'is_proforma' => false,
        'is_late_fee' => false,
    ];

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('created_at');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->oldest('occurred_at');
    }

    /**
     * @return HasMany<CreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * What is still owed.
     *
     * Derived rather than stored: a second column that can disagree with
     * the first is a bug waiting for a rounding error.
     */
    public function balance(): Money
    {
        return $this->total->minus($this->paid);
    }

    public function isFullyPaid(): bool
    {
        return ! $this->balance()->isPositive();
    }

    /**
     * Whether the due date has passed with money still owed.
     *
     * Asked rather than assumed from the status, because the status only
     * changes when something runs — and nothing runs at midnight until
     * Phase 9.
     */
    public function isPastDue(?CarbonImmutable $now = null): bool
    {
        return $this->status->isOwed()
            && $this->due_on !== null
            && $this->due_on->isBefore(($now ?? CarbonImmutable::now())->startOfDay());
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeOwed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Unpaid->value,
            InvoiceStatus::PartiallyPaid->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => MoneyCast::class.':subtotal_minor,currency_code',
            'discount' => MoneyCast::class.':discount_minor,currency_code',
            'tax' => MoneyCast::class.':tax_minor,currency_code',
            'total' => MoneyCast::class.':total_minor,currency_code',
            'paid' => MoneyCast::class.':paid_minor,currency_code',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'tax_breakdown' => 'array',
            'is_proforma' => 'boolean',
            'is_late_fee' => 'boolean',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'last_capture_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
