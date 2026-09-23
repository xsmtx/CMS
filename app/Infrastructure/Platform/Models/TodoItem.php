<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Models;

use App\Domain\Platform\TodoStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\TodoItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note to come back to something.
 *
 * Owned by an organization like everything else, so a reseller's list is
 * theirs and the provider does not read it. Assignment is optional on
 * purpose: a list where everything must be assigned before it can be
 * written down is a list nobody writes anything down in.
 *
 * @property string $id
 * @property string $title
 * @property string|null $body
 * @property TodoStatus $status
 * @property CarbonImmutable|null $due_on
 * @property string|null $assigned_to
 * @property string|null $created_by
 * @property CarbonImmutable|null $completed_at
 */
final class TodoItem extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<TodoItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'todo_items';

    protected $fillable = [
        'organization_id',
        'title',
        'body',
        'status',
        'due_on',
        'assigned_to',
        'created_by',
        'completed_at',
    ];

    /** @var array<string, string> */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'assigned_to');
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TodoStatus::class,
            'due_on' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
