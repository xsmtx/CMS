<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Models;

use App\Domain\Support\TicketPriority;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a ticket goes, and how fast it has to be answered.
 *
 * The SLA is stated for normal priority and scaled from there, so an
 * operator configuring "billing answers within four hours" sets one number
 * instead of one per priority.
 *
 * A department with no SLA is a department whose tickets have no due dates.
 * That is a real configuration — not every queue is measured — and not an
 * error to be defaulted away.
 *
 * @property string $name
 * @property string $slug
 * @property string|null $email
 * @property int|null $first_response_minutes
 * @property int|null $resolution_minutes
 * @property bool $is_public
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 */
final class Department extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'support_departments';

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'email',
        'description',
        'first_response_minutes',
        'resolution_minutes',
        'is_public',
        'is_active',
        'position',
    ];

    /**
     * @var array<string, bool|int>
     */
    protected $attributes = [
        'is_public' => true,
        'is_active' => true,
        'position' => 0,
    ];

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Minutes to first response at this priority, or null when the
     * department is not measured.
     */
    public function firstResponseMinutesFor(TicketPriority $priority): ?int
    {
        return $this->scaled($this->first_response_minutes, $priority);
    }

    public function resolutionMinutesFor(TicketPriority $priority): ?int
    {
        return $this->scaled($this->resolution_minutes, $priority);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    private function scaled(?int $minutes, TicketPriority $priority): ?int
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        // At least a minute: an urgent ticket in a fifteen-minute queue
        // rounds to four, not to zero.
        return max(1, (int) round($minutes * $priority->slaFactor()));
    }
}
