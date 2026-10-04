<?php

declare(strict_types=1);

namespace App\Infrastructure\Vendors\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\LicencePoolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operational licences bought in quantity (§24).
 *
 * cPanel, CloudLinux, LiteSpeed, Imunify, Windows: a seat count somebody
 * bought and a per-seat price. What makes it worth a table rather than a
 * spreadsheet is `allocations` — which machine is using one — because that is
 * the half no vendor portal knows.
 *
 * **Over-allocation is allowed and shown, never refused.** A hundred and one
 * machines on a hundred seats is a real and expensive situation, and a
 * platform that refused the hundred-and-first allocation would be hiding it
 * from the one person who can fix it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $vendor_id
 * @property string|null $contract_id
 * @property string $name
 * @property string|null $for_module
 * @property int $seats
 * @property string $currency_code
 * @property int $unit_amount_minor
 * @property string|null $note
 * @property int|null $allocations_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LicencePool extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<LicencePoolFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'licence_pools';

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'contract_id',
        'name',
        'for_module',
        'seats',
        'currency_code',
        'unit_amount_minor',
        'note',
    ];

    /**
     * The database defaults, declared again — a default fills the row and
     * leaves the model in memory without the attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'seats' => 0,
        'unit_amount_minor' => 0,
    ];

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return HasMany<LicenceAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LicenceAllocation::class);
    }

    public function unitPrice(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency_code);
    }

    /**
     * What the whole pool costs per period, whatever the contract's period is.
     *
     * Multiplied here rather than stored, because a seat count an operator
     * edits and a total column would disagree the first time somebody bought
     * ten more.
     */
    public function total(): Money
    {
        return Money::ofMinor($this->unit_amount_minor * $this->seats, $this->currency_code);
    }

    /**
     * Seats paid for and attached to nothing.
     *
     * Negative when more machines hold a seat than were bought, and that is
     * the point: the overage is the expensive finding, and a figure clamped
     * at zero would say everything is fine.
     */
    public function spare(): int
    {
        return $this->seats - ($this->allocations_count ?? $this->allocations()->count());
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'unit_amount_minor' => 'integer',
        ];
    }
}
