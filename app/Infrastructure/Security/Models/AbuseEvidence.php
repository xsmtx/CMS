<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Domain\Security\EvidenceKind;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AbuseEvidenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reference to something that proves the complaint — with a deadline.
 *
 * **`retain_until` is the first column in this product whose job is to make
 * something be forgotten.** A complaint holds a third party's data, and
 * keeping it for ever is a privacy decision nobody made. Core stores an id, a
 * URL, a hash or a bounded excerpt and never a mail body, a full log or a
 * disk image; a sweep deletes what is past its deadline.
 *
 * Deleting evidence does not close the case or touch its timeline. What the
 * desk decided stays; what the complaint contained does not.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $abuse_case_id
 * @property EvidenceKind $kind
 * @property string $reference
 * @property string|null $captured_by
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable $retain_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AbuseEvidence extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AbuseEvidenceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'abuse_evidence';

    protected $fillable = [
        'organization_id',
        'abuse_case_id',
        'kind',
        'reference',
        'captured_by',
        'captured_at',
        'retain_until',
    ];

    /**
     * @return BelongsTo<AbuseCase, $this>
     */
    public function abuseCase(): BelongsTo
    {
        return $this->belongsTo(AbuseCase::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function capturer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'captured_by');
    }

    /**
     * Past its deadline. A question about rows, asked when the sweep runs —
     * so a scheduler that was down for a week catches up rather than leaving
     * a fortnight of somebody's data behind permanently.
     *
     * @param  Builder<AbuseEvidence>  $query
     * @return Builder<AbuseEvidence>
     */
    protected function scopeExpired(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query->where('retain_until', '<=', $at ?? CarbonImmutable::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EvidenceKind::class,
            'captured_at' => 'immutable_datetime',
            'retain_until' => 'immutable_datetime',
        ];
    }
}
