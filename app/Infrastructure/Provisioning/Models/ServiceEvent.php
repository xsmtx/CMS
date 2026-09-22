<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ServiceOperation;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was attempted on a service, and how it went.
 *
 * Append-only, like the audit log and the ledger. It is the first thing
 * anybody reads when a customer says their account stopped working, so it
 * records the failures as carefully as the successes — with the message
 * already sanitised, because a provider's error text has a habit of
 * containing the request that caused it.
 *
 * @property ServiceOperation $operation
 * @property OperationOutcome $outcome
 * @property string|null $actor_label
 * @property string|null $message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 */
final class ServiceEvent extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ServiceEventFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'service_events';

    protected $fillable = [
        'organization_id',
        'service_id',
        'operation',
        'outcome',
        'actor_label',
        'message',
        'metadata',
        'correlation_id',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => ServiceOperation::class,
            'outcome' => OperationOutcome::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
