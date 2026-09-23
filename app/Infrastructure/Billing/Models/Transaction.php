<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One movement of money.
 *
 * The ledger is the truth. An invoice's paid amount is a cached total of
 * these rows and can be rebuilt from them; if the two ever disagree, the
 * rows win.
 *
 * Append-only, like the audit trail and for the same reason: a financial
 * history that can be rewritten is not a history.
 *
 * @property TransactionKind $kind
 * @property Money $amount
 * @property Money $credit_balance
 * @property Money $fees
 * @property CarbonImmutable $occurred_at
 */
final class Transaction extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'transactions';

    protected $guarded = [];

    /**
     * The database defaults, repeated.
     *
     * A default fills the row but leaves the model in memory without the
     * attribute, and `MoneyCast` reads that absence as null. It bit the
     * money columns in Phase 4; `fees_minor` is the same shape.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'credit_balance_minor' => 0,
        'fees_minor' => 0,
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new RuntimeException('Transactions are append-only and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Transactions are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'amount' => MoneyCast::class.':amount_minor,currency_code',
            'credit_balance' => MoneyCast::class.':credit_balance_minor,currency_code',
            'fees' => MoneyCast::class.':fees_minor,currency_code',
            'amount_minor' => 'integer',
            'credit_balance_minor' => 'integer',
            'fees_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
