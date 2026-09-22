<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\CreditNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A correction to an issued invoice.
 *
 * Its own numbered document, and append-only, because that is how
 * accounting corrects things: nothing issued is edited or deleted, a second
 * document says what changed.
 *
 * @property string $number
 * @property Money $amount
 * @property string $reason
 * @property CarbonImmutable $issued_on
 */
final class CreditNote extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CreditNoteFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'credit_notes';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'number',
        'currency_code',
        'amount_minor',
        'reason',
        'issued_by',
        'issued_on',
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new RuntimeException('Credit notes are issued documents and cannot be changed.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Credit notes are issued documents and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency_code',
            'amount_minor' => 'integer',
            'issued_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
