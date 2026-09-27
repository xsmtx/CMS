<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Security\AbuseKind;
use App\Domain\Security\AbuseState;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AbuseCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody outside complaining about somebody inside (§13).
 *
 * The third record in the reliability family and the one that points the
 * other way: an incident is the platform's fault, a ticket is a conversation,
 * and this is the customer's fault — or their compromised account's. It ends
 * in a decision rather than in "resolved", because "we acted", "nothing
 * needed doing" and "the complaint was wrong" are three different answers and
 * flattening them tells the next reader nothing.
 *
 * `occurred_at` is when the complaint says it happened and is what
 * attribution is done against. A report about last Tuesday that lands on
 * Friday belongs to Tuesday's holder of the address.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property AbuseKind $kind
 * @property AbuseState $state
 * @property AlertSeverity $severity
 * @property string|null $source
 * @property string|null $external_reference
 * @property string $summary
 * @property string|null $subject_type
 * @property string|null $subject_value
 * @property string|null $customer_id
 * @property string|null $service_id
 * @property string|null $domain_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $reported_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property string|null $opened_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AbuseCase extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AbuseCaseFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'abuse_cases';

    protected $fillable = [
        'organization_id',
        'reference',
        'kind',
        'state',
        'severity',
        'source',
        'external_reference',
        'summary',
        'subject_type',
        'subject_value',
        'customer_id',
        'service_id',
        'domain_id',
        'occurred_at',
        'reported_at',
        'closed_at',
        'closed_by',
        'opened_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'open',
    ];

    /**
     * @return HasMany<AbuseCaseEvent, $this>
     */
    public function events(): HasMany
    {
        // Newest first and tie-broken on the ULID, the rule an incident's
        // timeline learned: `created_at` has a resolution of one second and
        // two entries inside it came back in whatever order the database
        // felt like.
        return $this->hasMany(AbuseCaseEvent::class)->latest()
            ->orderByDesc('id');
    }

    /**
     * @return HasMany<AbuseEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(AbuseEvidence::class)->latest('captured_at');
    }

    /**
     * @return HasMany<AbuseActionRecord, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AbuseActionRecord::class)->oldest();
    }

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

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'opened_by');
    }

    public function auditLabel(): string
    {
        return $this->reference.' — '.$this->summary;
    }

    /**
     * @param  Builder<AbuseCase>  $query
     * @return Builder<AbuseCase>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', [
            AbuseState::Open->value,
            AbuseState::Investigating->value,
            AbuseState::WaitingCustomer->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AbuseKind::class,
            'state' => AbuseState::class,
            'severity' => AlertSeverity::class,
            'occurred_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
