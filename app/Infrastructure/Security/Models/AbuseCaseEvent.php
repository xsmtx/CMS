<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Domain\Security\AbuseState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AbuseCaseEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an abuse case's history.
 *
 * Append-only, like an incident's timeline and for the same reason: what the
 * desk believed when it replied to the complainant is evidence, and a body
 * overwritten at each step loses exactly the part that matters afterwards.
 * The state is copied onto the row so the timeline still reads correctly
 * after the next three moves.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $abuse_case_id
 * @property string|null $written_by
 * @property AbuseState $state
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AbuseCaseEvent extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AbuseCaseEventFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'abuse_case_id',
        'written_by',
        'state',
        'body',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'written_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => AbuseState::class];
    }
}
