<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence\Models;

use App\Domain\Intelligence\ProposalState;
use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Intelligence\RemediationAction;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\RemediationProposalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What could be done about a difference, and who agreed to it (§22).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reconciliation_finding_id
 * @property RemediationAction $action
 * @property ProposalState $state
 * @property ReconciliationClass $finding_class
 * @property string|null $proposed_by
 * @property string|null $decided_by
 * @property string|null $reason
 * @property string|null $outcome
 * @property CarbonImmutable $proposed_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RemediationProposal extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<RemediationProposalFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'reconciliation_finding_id',
        'action',
        'state',
        'finding_class',
        'proposed_by',
        'decided_by',
        'reason',
        'outcome',
        'proposed_at',
        'decided_at',
        'applied_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'proposed',
    ];

    /**
     * @return BelongsTo<ReconciliationFinding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(ReconciliationFinding::class, 'reconciliation_finding_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by');
    }

    /**
     * Whether the finding is still what this was written about.
     *
     * The fingerprint check, for a comparison rather than a device. A
     * proposal about a finding that has moved is a proposal nobody agreed
     * to — and it is the difference between correcting a record and
     * terminating a live account because it looked terminated an hour ago.
     */
    public function matchesFinding(): bool
    {
        $finding = $this->finding;

        if ($finding === null) {
            return false;
        }

        return $finding->cleared_at === null && $finding->class === $this->finding_class;
    }

    public function auditLabel(): string
    {
        return (string) __($this->action->labelKey());
    }

    /**
     * @param  Builder<RemediationProposal>  $query
     * @return Builder<RemediationProposal>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', ProposalState::Proposed->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => RemediationAction::class,
            'state' => ProposalState::class,
            'finding_class' => ReconciliationClass::class,
            'proposed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
