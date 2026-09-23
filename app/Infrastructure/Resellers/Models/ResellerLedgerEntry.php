<?php

declare(strict_types=1);

namespace App\Infrastructure\Resellers\Models;

use App\Domain\Resellers\ResellerLedgerKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\ResellerLedgerEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement on a reseller's account with the provider.
 *
 * Append-only, like every financial history here. The amount is always
 * positive and the kind decides direction (ADR 0024), and `balance_minor` is
 * the running total **after** this row so a balance can be read without
 * summing the table — which also means the row records what the balance was
 * at the time, and that is the thing a reseller's statement is for.
 *
 * `organization_id` is the reseller, and the row is owned by them like
 * everything else. That is what makes "a reseller cannot see another
 * reseller's account" true by the same mechanism as everything else, rather
 * than by a clause somebody has to remember.
 *
 * No `updated_at`: there is no update. The table has `occurred_at` because
 * when the money moved and when the row was written are different facts, and
 * a provider recording last week's bank transfer needs the first.
 *
 * @property ResellerLedgerKind $kind
 * @property Money $amount
 * @property Money $balance
 * @property CarbonImmutable $occurred_at
 */
final class ResellerLedgerEntry extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ResellerLedgerEntryFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Written once, by `ResellerLedger`.
     *
     * Laravel's own timestamps are off because the table has none: an
     * append-only row's `occurred_at` is the only time it has, and a second
     * "when we wrote it" column nobody reads is a column that will disagree
     * with the first.
     */
    public $timestamps = false;

    protected $table = 'reseller_ledger_entries';

    protected $fillable = [
        'organization_id',
        'kind',
        'currency_code',
        'amount_minor',
        'balance_minor',
        'order_id',
        'invoice_id',
        'description',
        'recorded_by',
        'occurred_at',
    ];

    /** @var array<string, int> */
    protected $attributes = ['balance_minor' => 0];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The amount as it reads on a statement: negative when it took money
     * away.
     *
     * Derived rather than stored, because the stored number is positive on
     * purpose — a ledger where the sign and the kind can disagree is a
     * ledger that lies twice.
     */
    public function signedMinor(): int
    {
        return $this->kind->increasesBalance() ? $this->amount_minor : -$this->amount_minor;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ResellerLedgerKind::class,
            'amount' => MoneyCast::class.':amount_minor,currency_code',
            'balance' => MoneyCast::class.':balance_minor,currency_code',
            'amount_minor' => 'integer',
            'balance_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
