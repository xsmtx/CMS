<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Models;

use App\Domain\Domains\DomainOperation;
use App\Domain\Provisioning\OperationOutcome;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\DomainEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was attempted on a domain, and how it went.
 *
 * Append-only, and the same shape as a service event on purpose: an
 * operator looking at "what happened to this customer" should not have to
 * learn two vocabularies.
 *
 * @property DomainOperation $operation
 * @property OperationOutcome $outcome
 * @property string|null $actor_label
 * @property string|null $message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 */
final class DomainEvent extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DomainEventFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'domain_events';

    protected $fillable = [
        'organization_id',
        'domain_id',
        'operation',
        'outcome',
        'actor_label',
        'message',
        'metadata',
        'correlation_id',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => DomainOperation::class,
            'outcome' => OperationOutcome::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
