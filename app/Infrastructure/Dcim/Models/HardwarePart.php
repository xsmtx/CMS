<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Domain\Dcim\PartKind;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\HardwarePartFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A part, and its warranty (§11).
 *
 * The row that outlives the machine. A disk fitted to `web-3` in 2024 and
 * moved to `web-7` in 2026 is one part with two fittings, and "where has this
 * serial been" is the question a warranty claim turns on.
 *
 * `warranty_until` in the past is a fact rather than an error — those are the
 * parts an operator most needs to track, because they are the ones about to
 * fail.
 *
 * @property string $id
 * @property string $organization_id
 * @property PartKind $kind
 * @property string|null $model
 * @property string|null $serial
 * @property string|null $asset_tag
 * @property string|null $vendor
 * @property CarbonImmutable|null $purchased_on
 * @property CarbonImmutable|null $warranty_until
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class HardwarePart extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<HardwarePartFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'kind',
        'model',
        'serial',
        'asset_tag',
        'vendor',
        'purchased_on',
        'warranty_until',
        'note',
    ];

    /**
     * @return HasMany<PartFitting, $this>
     */
    public function fittings(): HasMany
    {
        return $this->hasMany(PartFitting::class);
    }

    /**
     * Where it is now, if anywhere.
     *
     * @return HasOne<PartFitting, $this>
     */
    public function currentFitting(): HasOne
    {
        return $this->hasOne(PartFitting::class)->whereNull('removed_at');
    }

    /**
     * Whether the warranty has run out, as of a given day.
     *
     * Null where there is no warranty date — which is not "expired". A part
     * somebody never recorded a warranty for is a gap in the register, and
     * drawing it as out of warranty would send somebody to argue with a
     * vendor who is still obliged.
     */
    public function isOutOfWarranty(?CarbonImmutable $on = null): ?bool
    {
        if (! $this->warranty_until instanceof CarbonImmutable) {
            return null;
        }

        return $this->warranty_until->lessThan($on ?? CarbonImmutable::now());
    }

    public function displayName(): string
    {
        return $this->serial ?? $this->asset_tag ?? $this->model ?? (string) __($this->kind->labelKey());
    }

    public function auditLabel(): string
    {
        return $this->displayName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PartKind::class,
            'purchased_on' => 'immutable_date',
            'warranty_until' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
