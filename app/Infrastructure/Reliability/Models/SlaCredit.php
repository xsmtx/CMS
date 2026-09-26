<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\SlaCreditFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This credit note was for that outage."
 *
 * A link rather than a second ledger: the money moved once, through
 * `IssueCreditNote`, and the credit note and its transaction are where it
 * lives. What this row adds is the one fact neither of them can hold — which
 * incident it was about — so "what did outages cost us last quarter" is a
 * `where` rather than an afternoon reading sentences.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $incident_id
 * @property string $customer_id
 * @property string $invoice_id
 * @property string $credit_note_id
 * @property int $amount_minor
 * @property string $currency_code
 * @property string $reason
 * @property string|null $issued_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class SlaCredit extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<SlaCreditFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'incident_id',
        'customer_id',
        'invoice_id',
        'credit_note_id',
        'amount_minor',
        'currency_code',
        'reason',
        'issued_by',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

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
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'issued_by');
    }

    /** Integer minor units and an ISO code, never a float. */
    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency_code);
    }

    public function auditLabel(): string
    {
        return $this->amount()->toDecimalString().' '.$this->currency_code;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
