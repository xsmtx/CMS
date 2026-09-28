<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ReputationListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of this installation's addresses, on one blocklist (§13).
 *
 * Raised once, kept while it stays true, cleared rather than deleted. The
 * cleared row is the answer to "how long were we listed", which is the
 * question somebody asks while writing to a customer about it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $address
 * @property string $address_bytes
 * @property string $list
 * @property string|null $reason
 * @property string|null $delist_url
 * @property string|null $customer_id
 * @property string|null $service_id
 * @property string $source
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $cleared_at
 * @property string $cleared_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ReputationListing extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ReputationListingFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'address',
        'address_bytes',
        'list',
        'reason',
        'delist_url',
        'customer_id',
        'service_id',
        'source',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'cleared_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cleared_token' => '',
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

    public function isOpen(): bool
    {
        return $this->cleared_at === null;
    }

    public function auditLabel(): string
    {
        return $this->address.' on '.$this->list;
    }

    /**
     * Still listed.
     *
     * @param  Builder<ReputationListing>  $query
     * @return Builder<ReputationListing>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }
}
