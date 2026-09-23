<?php

declare(strict_types=1);

namespace App\Infrastructure\Automation\Models;

use App\Domain\Automation\DunningAction;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\DunningStepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One step in what happens to an unpaid invoice.
 *
 * `offset_days` is signed: negative is before the due date, which is where
 * the reminder that actually gets paid lives. A sequence with no suspend
 * step is a real configuration for a business that wants a human decision
 * first, not an incomplete one to be defaulted away.
 *
 * @property int $offset_days
 * @property DunningAction $action
 * @property string|null $event
 * @property bool $is_active
 * @property int $position
 */
final class DunningStep extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<DunningStepFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'dunning_steps';

    protected $fillable = [
        'organization_id',
        'offset_days',
        'action',
        'event',
        'is_active',
        'position',
    ];

    /** @var array<string, bool|int> */
    protected $attributes = [
        'is_active' => true,
        'position' => 0,
    ];

    public function notificationEvent(): ?NotificationEvent
    {
        return $this->event === null ? null : NotificationEvent::tryFrom($this->event);
    }

    /**
     * Whether this step is due for an invoice with the given due date.
     *
     * Asked of the invoice's own dates rather than of "today minus seven",
     * so a step that should have run while the scheduler was down runs on
     * the next sweep instead of being skipped forever.
     */
    public function isDueFor(CarbonInterface $dueOn): bool
    {
        return $dueOn->copy()->addDays($this->offset_days)->startOfDay()
            ->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public function auditLabel(): string
    {
        return $this->action->value.' '.($this->offset_days >= 0 ? '+' : '').$this->offset_days;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('offset_days');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DunningAction::class,
            'offset_days' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
