<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentUpdateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody said during an incident.
 *
 * Append-only, like the ledger and like the graph's edges. A single body that
 * was overwritten at each update would lose exactly the part a postmortem is
 * written from: what was believed at half past two, before anybody knew what
 * it was.
 *
 * `state` is copied onto the row rather than read through the incident,
 * because the incident moves on and an update that said "investigating" must
 * go on saying it — the same reason an issued invoice copies its bill-to
 * party (ADR 0023).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $incident_id
 * @property string|null $written_by
 * @property IncidentState $state
 * @property string $body
 * @property bool $is_public
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IncidentUpdate extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<IncidentUpdateFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'incident_id',
        'written_by',
        'state',
        'body',
        'is_public',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_public' => false];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'written_by');
    }

    public function auditLabel(): string
    {
        return mb_substr($this->body, 0, 60);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => IncidentState::class,
            'is_public' => 'boolean',
        ];
    }
}
