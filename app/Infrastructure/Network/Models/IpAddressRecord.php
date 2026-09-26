<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Domain\Network\AddressState;
use App\Domain\Network\IpAddress;
use App\Domain\Network\IpFamily;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\IpAddressRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An address somebody has done something with.
 *
 * There is no row for a free address, and that is the design rather than an
 * omission: a /64 holds eighteen quintillion of them. Free is the prefix's range
 * minus these rows.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $ip_prefix_id
 * @property string $address
 * @property string $address_bytes
 * @property IpFamily $family
 * @property AddressState $state
 * @property string|null $reverse_dns
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IpAddressRecord extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<IpAddressRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'ip_addresses';

    protected $fillable = [
        'organization_id',
        'ip_prefix_id',
        'address',
        'address_bytes',
        'family',
        'state',
        'reverse_dns',
        'note',
    ];

    /** @var array<string, string> */
    protected $attributes = ['state' => AddressState::Available->value];

    /**
     * An unsaved row for one address in one prefix.
     *
     * The one place the derived columns are written, so a row whose text says
     * one thing and whose bytes say another cannot be built by accident — and
     * the bytes are what every query reads. Assigned property by property
     * rather than through an array, because an array of column names is a
     * shape a caller can get subtly wrong and nothing would say so.
     */
    public static function within(IpPrefixRecord $prefix, IpAddress $address, AddressState $state): self
    {
        $record = new self;

        $record->organization_id = $prefix->organization_id;
        $record->ip_prefix_id = $prefix->id;
        $record->address = $address->text();
        $record->address_bytes = $address->bytes;
        $record->family = $address->family;
        $record->state = $state;

        return $record;
    }

    public function ip(): IpAddress
    {
        return IpAddress::fromBytes($this->address_bytes, $this->family);
    }

    /**
     * @return BelongsTo<IpPrefixRecord, $this>
     */
    public function prefix(): BelongsTo
    {
        return $this->belongsTo(IpPrefixRecord::class, 'ip_prefix_id');
    }

    /**
     * @return HasMany<IpAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(IpAssignment::class, 'ip_address_id');
    }

    /**
     * Who holds it now, if anybody.
     *
     * One open row at a time is the invariant `AssignAddress` keeps; this is
     * how every screen reads it, so nothing has to know that a closed row means
     * released.
     *
     * @return HasOne<IpAssignment, $this>
     */
    public function assignment(): HasOne
    {
        return $this->hasOne(IpAssignment::class, 'ip_address_id')->whereNull('released_at');
    }

    public function auditLabel(): string
    {
        return $this->address;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => IpFamily::class,
            'state' => AddressState::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
