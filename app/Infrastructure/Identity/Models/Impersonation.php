<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ImpersonationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A staff member acting as a customer.
 *
 * The row exists so the act is reviewable afterwards: who, whom, why, from
 * where, and for how long. The reason is not optional, because a review with
 * no stated intent tells nobody anything.
 *
 * @property string $id
 * @property string $impersonator_id
 * @property string $reason
 * @property string|null $ip_address
 * @property string|null $correlation_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
final class Impersonation extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ImpersonationFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'impersonations';

    protected $guarded = [];

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'impersonator_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
