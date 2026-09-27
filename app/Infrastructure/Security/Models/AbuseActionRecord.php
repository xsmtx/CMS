<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Domain\Security\AbuseAction;
use App\Domain\Security\AbuseActionState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AbuseActionRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the desk decided to do to an account.
 *
 * Named `AbuseActionRecord` rather than `AbuseAction` because the enum owns
 * that name: the enum is *what may be done*, and this is *that somebody
 * decided to do it*. One of them is a vocabulary and the other is a fact, and
 * a model sharing the name would make every import ambiguous.
 *
 * `manual` is a real end state. Three of the four actions have no seam in
 * this product, and recording the decision while saying plainly that a person
 * still has to carry it out is the honesty of the `Manual*` adapters applied
 * to a decision about a customer.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $abuse_case_id
 * @property AbuseAction $action
 * @property AbuseActionState $state
 * @property string $reason
 * @property string|null $decided_by
 * @property string|null $service_id
 * @property string|null $result
 * @property CarbonImmutable|null $performed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AbuseActionRecord extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AbuseActionRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'abuse_actions';

    protected $fillable = [
        'organization_id',
        'abuse_case_id',
        'action',
        'state',
        'reason',
        'decided_by',
        'service_id',
        'result',
        'performed_at',
    ];

    /**
     * @return BelongsTo<AbuseCase, $this>
     */
    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by');
    }

    public function auditLabel(): string
    {
        return $this->action->value;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AbuseAction::class,
            'state' => AbuseActionState::class,
            'performed_at' => 'immutable_datetime',
        ];
    }
}
