<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Billing\PaymentStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to pay.
 *
 * An attempt, not a fact: `pending` is the normal first state, because most
 * gateways answer asynchronously and what actually happened arrives on a
 * webhook. Only a completed payment counts toward an invoice.
 *
 * @property PaymentStatus $status
 * @property Money $amount
 * @property Money $refunded
 * @property Money $fees
 * @property string|null $reference
 * @property CarbonImmutable|null $received_at
 */
final class Payment extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'payments';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'customer_id',
        'gateway',
        'status',
        'currency_code',
        'amount_minor',
        'refunded_minor',
        'fees_minor',
        'reference',
        'idempotency_key',
        'failure_reason',
        'received_at',
        'failed_at',
        'recorded_by',
        'note',
    ];

    /**
     * @var array<string, int>
     */
    protected $attributes = [
        'amount_minor' => 0,
        'refunded_minor' => 0,
        'fees_minor' => 0,
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * How much of this payment could still be sent back.
     */
    public function refundable(): Money
    {
        return $this->status->isRefundable()
            ? $this->amount->minus($this->refunded)
            : $this->amount->multipliedBy(0);
    }

    public function auditLabel(): string
    {
        return $this->reference ?? $this->id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => MoneyCast::class.':amount_minor,currency_code',
            'refunded' => MoneyCast::class.':refunded_minor,currency_code',
            'fees' => MoneyCast::class.':fees_minor,currency_code',
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'fees_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
