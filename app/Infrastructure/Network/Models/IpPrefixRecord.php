<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Domain\Network\IpAddress;
use App\Domain\Network\IpFamily;
use App\Domain\Network\IpPrefix;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\IpPrefixRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One network.
 *
 * Named `IpPrefixRecord` rather than `IpPrefix` on purpose: `App\Domain\Network\IpPrefix`
 * is the value object that knows the arithmetic, and a model of the same short
 * name two namespaces away is the import somebody gets wrong at four in the
 * afternoon. The row holds what an operator wrote down; the value object
 * answers what is inside it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $ip_pool_id
 * @property string|null $parent_id
 * @property string|null $vlan_id
 * @property string $cidr
 * @property string $network_bytes
 * @property string $broadcast_bytes
 * @property int $prefix_length
 * @property IpFamily $family
 * @property string|null $gateway
 * @property string|null $site
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IpPrefixRecord extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<IpPrefixRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'ip_prefixes';

    protected $fillable = [
        'organization_id',
        'ip_pool_id',
        'parent_id',
        'vlan_id',
        'cidr',
        'network_bytes',
        'broadcast_bytes',
        'prefix_length',
        'family',
        'gateway',
        'site',
        'note',
    ];

    /**
     * The columns derived from the prefix itself, in one place.
     *
     * Every writer goes through here, so a row whose `cidr` says one thing and
     * whose bytes say another cannot be written by accident — and the bytes are
     * what every query reads.
     *
     * @return array<string, string|int>
     */
    public static function columnsFor(IpPrefix $prefix): array
    {
        return [
            'cidr' => $prefix->text(),
            'network_bytes' => $prefix->firstAddress()->bytes,
            'broadcast_bytes' => $prefix->lastAddress()->bytes,
            'prefix_length' => $prefix->length,
            'family' => $prefix->family->value,
        ];
    }

    public function prefix(): IpPrefix
    {
        return IpPrefix::of(
            IpAddress::fromBytes($this->network_bytes, $this->family),
            $this->prefix_length,
        );
    }

    /**
     * @return BelongsTo<IpPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class, 'vlan_id');
    }

    /**
     * @return HasMany<IpAddressRecord, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddressRecord::class, 'ip_prefix_id');
    }

    public function auditLabel(): string
    {
        return $this->cidr;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => IpFamily::class,
            'prefix_length' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
