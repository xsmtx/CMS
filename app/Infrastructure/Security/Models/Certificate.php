<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certificate somebody deployed, as this platform found it (§8).
 *
 * **Never issued here.** Core does not run ACME; it reads what is deployed
 * and answers the questions that need no private key: what expires soon, what
 * is served under a name it does not cover, whose customer is affected.
 *
 * `not_after` comes from the certificate and is never computed or defaulted —
 * an expiry this installation guessed would be worse than none, because
 * somebody would act on it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source
 * @property string $fingerprint
 * @property string $common_name
 * @property list<string> $subject_alternative_names
 * @property string $issuer
 * @property string|null $serial
 * @property CarbonImmutable $not_before
 * @property CarbonImmutable $not_after
 * @property bool|null $chain_ok
 * @property string|null $resource_node_id
 * @property string|null $domain_id
 * @property string|null $service_id
 * @property string|null $customer_id
 * @property CarbonImmutable $discovered_at
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Certificate extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'source',
        'fingerprint',
        'common_name',
        'subject_alternative_names',
        'issuer',
        'serial',
        'not_before',
        'not_after',
        'chain_ok',
        'resource_node_id',
        'domain_id',
        'service_id',
        'customer_id',
        'discovered_at',
        'retired_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'subject_alternative_names' => '[]',
    ];

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'resource_node_id');
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Days until it expires, negative once it has.
     *
     * Whole days rather than hours: nobody schedules a renewal to the hour,
     * and "expires in 13.7 days" is a figure that reads as precision the
     * platform does not have.
     */
    public function daysRemaining(?CarbonImmutable $at = null): int
    {
        return (int) ($at ?? CarbonImmutable::now())->startOfDay()
            ->diffInDays($this->not_after->startOfDay(), absolute: false);
    }

    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        return $this->not_after->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    /**
     * Whether it covers a hostname.
     *
     * A wildcard covers one label and no more: `*.example.com` is `a.example.com`
     * and not `a.b.example.com`, and not `example.com` either. Getting that
     * wrong in the lenient direction would have this platform reporting a
     * name as covered when a browser would refuse it, which is the one answer
     * worse than no answer.
     */
    public function covers(string $hostname): bool
    {
        $host = mb_strtolower(trim($hostname, '.'));

        foreach ($this->subject_alternative_names as $name) {
            $candidate = mb_strtolower(trim((string) $name, '.'));

            if ($candidate === $host) {
                return true;
            }

            if (! str_starts_with($candidate, '*.')) {
                continue;
            }

            $suffix = substr($candidate, 2);
            $head = substr($host, 0, max(0, strlen($host) - strlen($suffix) - 1));

            if (str_ends_with($host, '.'.$suffix) && $head !== '' && ! str_contains($head, '.')) {
                return true;
            }
        }

        return false;
    }

    public function auditLabel(): string
    {
        return $this->common_name;
    }

    /**
     * Still deployed. A retired certificate is kept, like a retired graph
     * node: "this was on that machine in March" is a question somebody asks
     * after an outage.
     *
     * @param  Builder<Certificate>  $query
     * @return Builder<Certificate>
     */
    protected function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_alternative_names' => 'array',
            'not_before' => 'immutable_datetime',
            'not_after' => 'immutable_datetime',
            'chain_ok' => 'boolean',
            'discovered_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
