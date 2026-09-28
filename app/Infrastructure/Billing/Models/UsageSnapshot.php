<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Billing\UsageUnit;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\UsageSnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one meter measured over one closed period (§25).
 *
 * **Append-only, and quoted by an invoice line rather than recomputed.** An
 * invoice is frozen the moment it is issued (ADR 0023), so the number on it
 * must never be worked out again: the snapshot carries the invoice item that
 * quoted it and can never be quoted twice. A meter that later revises history
 * writes a second snapshot and the correction is a credit note.
 *
 * `unit` is copied from the meter rather than read through it, for the reason
 * an order line copies the catalog (ADR 0021): the meter's unit may change,
 * and this row is what somebody was charged for.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $usage_meter_id
 * @property string $service_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $quantity
 * @property UsageUnit $unit
 * @property string $source
 * @property string|null $invoice_item_id
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UsageSnapshot extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<UsageSnapshotFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'usage_meter_id',
        'service_id',
        'period_start',
        'period_end',
        'quantity',
        'unit',
        'source',
        'invoice_item_id',
        'recorded_at',
    ];

    /**
     * @return BelongsTo<UsageMeterRecord, $this>
     */
    public function meter(): BelongsTo
    {
        return $this->belongsTo(UsageMeterRecord::class, 'usage_meter_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function amount(): float
    {
        return (float) $this->quantity;
    }

    public function isCharged(): bool
    {
        return $this->invoice_item_id !== null;
    }

    public function auditLabel(): string
    {
        return $this->period_start->toDateString().' — '.$this->period_end->toDateString();
    }

    /**
     * Measured, and nothing has charged for it yet.
     *
     * @param  Builder<UsageSnapshot>  $query
     * @return Builder<UsageSnapshot>
     */
    protected function scopeUncharged(Builder $query): Builder
    {
        return $query->whereNull('invoice_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => UsageUnit::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
