<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\BillingSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A seller's billing terms.
 *
 * One row per seller, like the tax settings beside it and for the same reason:
 * a reseller in another country sells on their own terms, and a second row for
 * one organization would be two answers with no way to say which won.
 *
 * Every default here is declared in `$attributes` as well as in the migration.
 * A database default fills the row and leaves the model in memory without the
 * attribute, and a cast reads that absence as null — which bit the money columns
 * in Phase 4 and the booleans in Phase 7.
 *
 * @property string $id
 * @property string $organization_id
 * @property int $due_days
 * @property int $late_fee_rate_ppm
 * @property string|null $late_fee_label
 * @property string|null $document_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BillingSetting extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<BillingSettingFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'billing_settings';

    protected $fillable = [
        'organization_id',
        'due_days',
        'late_fee_rate_ppm',
        'late_fee_label',
        'document_note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'due_days' => 14,
        'late_fee_rate_ppm' => 0,
    ];

    /**
     * The fee rate as the percentage an operator typed, for a form and a table.
     *
     * Integer division and a trimmed remainder rather than a float format: a
     * rate stored as 15 000 is 1.5, and printing `1.5000` in an input an
     * operator then saves is how a number grows decimal places nobody added.
     */
    public function lateFeePercentage(): string
    {
        $whole = intdiv($this->late_fee_rate_ppm, 10_000);
        $fraction = $this->late_fee_rate_ppm % 10_000;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole.'.'.rtrim(str_pad((string) $fraction, 4, '0', STR_PAD_LEFT), '0');
    }

    public function chargesLateFee(): bool
    {
        return $this->late_fee_rate_ppm > 0;
    }

    /**
     * The fee on an outstanding amount.
     *
     * `Money::percentage()` is the one place money is multiplied, so the rate
     * becomes the decimal string it expects and the rounding rule stays in one
     * place rather than being re-decided here.
     */
    public function lateFeeOn(Money $outstanding): Money
    {
        return $outstanding->percentage($this->lateFeePercentage());
    }

    public function auditLabel(): string
    {
        return 'Billing settings';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_days' => 'integer',
            'late_fee_rate_ppm' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
