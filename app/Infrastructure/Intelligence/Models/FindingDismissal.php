<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence\Models;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\FindingDismissalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This one is deliberate" (§21, §22).
 *
 * **One table for every family of finding.** The act is the same whether
 * the finding is a machine the provider has and we do not, or a service
 * nothing is billing for; `source` says which family, exactly as it always
 * did. A second table would be a second answer to one question, and the
 * second one is always the one that misses the next feature.
 *
 * **Keyed the way a finding is keyed, not by a finding's id.** The whole
 * point is to survive the finding being cleared and raised again by tonight's
 * sweep; a foreign key to one row would last until the next run.
 *
 * **A row rather than a flag**, because a dismissal has an author, a reason
 * and usually a date it stops being true. A boolean would record none of
 * those, and the operator who inherits the queue would find a hundred
 * dismissed findings and nobody to ask.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source
 * @property string $resource
 * @property string|null $subject_id
 * @property string|null $remote_key
 * @property string|null $field
 * @property string|null $dismissed_by
 * @property string $reason
 * @property CarbonImmutable $dismissed_at
 * @property CarbonImmutable|null $until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class FindingDismissal extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<FindingDismissalFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'source',
        'resource',
        'subject_id',
        'remote_key',
        'field',
        'dismissed_by',
        'reason',
        'dismissed_at',
        'until',
    ];

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'dismissed_by');
    }

    /**
     * Whether it still applies.
     *
     * Asked when a sweep runs rather than stored as a state, which is ADR
     * 0031 applied to somebody's judgement: a dismissal whose window has
     * passed comes back by itself, without a scheduled task that *has* to
     * run for the queue to be honest.
     */
    public function applies(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->until === null || $this->until->greaterThan($at);
    }

    public function auditLabel(): string
    {
        return $this->resource.' '.($this->remote_key ?? $this->subject_id ?? '');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'immutable_datetime',
            'until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
