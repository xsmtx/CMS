<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Domain\Crm\CancellationStatus;
use App\Domain\Crm\CancellationType;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\CancellationRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer asking for something to stop.
 *
 * The paperwork, kept apart from the service's own status. `cancel_pending`
 * on the service says *that* it is going away; this says who asked, when,
 * why, and whether they wanted it off today or at the end of the term they
 * have already paid for. A status column cannot answer "show me everybody
 * leaving because we were too expensive", and that is the question a
 * cancellation queue exists to make answerable.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $service_id
 * @property CancellationType $type
 * @property CancellationStatus $status
 * @property string|null $reason
 * @property string|null $requested_by_label
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $completed_by
 */
final class CancellationRequest extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CancellationRequestFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'cancellation_requests';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'service_id',
        'type',
        'status',
        'reason',
        'requested_by_type',
        'requested_by_id',
        'requested_by_label',
        'requested_at',
        'completed_at',
        'completed_by',
    ];

    /**
     * The column defaults fill the row and leave the model in memory
     * without them, and a cast reads that absence as null.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'type' => 'end_of_term',
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function auditLabel(): string
    {
        $service = $this->service;

        return 'Cancellation of '.($service instanceof Service ? $service->name : $this->service_id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePending(Builder $query): Builder
    {
        return $query->where('status', CancellationStatus::Pending->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CancellationType::class,
            'status' => CancellationStatus::class,
            'requested_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
