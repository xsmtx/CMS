<?php

declare(strict_types=1);

namespace App\Infrastructure\Resources\Models;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\CapabilitySet;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceAdapterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An adapter, and what this installation has decided about it.
 *
 * The row exists for two reasons that have nothing to do with caching.
 *
 * **`writes_enabled` is the operator's decision and it lives here.** An adapter
 * declares what it *can* do; this says what it *may*. The same package should be
 * a read-only window on one installation and a control plane on another, by
 * choice rather than by which package was installed — so a monitoring adapter
 * pointed at a firewall cannot change it merely because the SDK supports both.
 * Default false, and turning it on is audited.
 *
 * **`capabilities` is stored so the screen works while the package is not
 * loaded.** Exactly the reason a module's registration is stored on its row
 * (ADR 0038): an operator has to be able to read what an adapter would do — and
 * to disable it — without the platform first running the package they are trying
 * to be rid of.
 *
 * It never holds a credential. Those wait for the `SecretStore` in Phase B,
 * because nothing in Phase A makes an outbound call and a vault with no caller
 * is a vault that is wrong in ways only the first caller finds.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $adapter_key
 * @property string $name
 * @property string $vendor
 * @property string|null $module
 * @property array<int, string>|null $capabilities
 * @property bool $enabled
 * @property bool $writes_enabled
 * @property string $health
 * @property string|null $health_message
 * @property string|null $remote_version
 * @property bool $supported
 * @property CarbonImmutable|null $health_checked_at
 * @property CarbonImmutable|null $last_collected_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ResourceAdapter extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ResourceAdapterFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'resource_adapters';

    protected $fillable = [
        'organization_id',
        'adapter_key',
        'name',
        'vendor',
        'module',
        'capabilities',
        'enabled',
        'writes_enabled',
        'health',
        'health_message',
        'remote_version',
        'supported',
        'health_checked_at',
        'last_collected_at',
    ];

    /**
     * Declared again, because a database default leaves the in-memory model
     * without the attribute and a boolean cast reads that absence as null. It
     * bit the booleans in Phase 7; `writes_enabled` reading as null would be a
     * worse version of the same bug.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'writes_enabled' => false,
        'health' => ResourceNode::HealthUnknown,
        'supported' => true,
    ];

    /**
     * What the adapter last declared, as a set.
     *
     * Rebuilt from the stored values rather than from the live adapter, so this
     * answers the same way whether or not the package is loaded.
     */
    public function declaredCapabilities(): CapabilitySet
    {
        return CapabilitySet::fromValues(array_values($this->capabilities ?? []));
    }

    /**
     * What it may actually be asked to do.
     *
     * Narrowing here rather than refusing at the call site is what makes it
     * impossible for a screen to offer a button the platform would then refuse:
     * an adapter without writes enabled has, as far as the rest of the platform
     * can see, never claimed a write capability.
     */
    public function permittedCapabilities(): CapabilitySet
    {
        return $this->narrow($this->declaredCapabilities());
    }

    /**
     * The same rule, applied to what the *running* package declares.
     *
     * One method rather than two, because there were briefly two and they
     * disagreed: this one allowed a disabled adapter its reads and
     * `RegisteredAdapter` allowed it nothing. A test found it. Whichever answer
     * is right, two of them is the bug — so the rule lives here and both callers
     * ask it.
     *
     * Disabled beats allowed: an operator switching an adapter off during an
     * incident must not also have to revoke its writes.
     */
    public function narrow(CapabilitySet $declared): CapabilitySet
    {
        if (! $this->enabled) {
            return CapabilitySet::of([]);
        }

        return $this->writes_enabled ? $declared : $declared->readOnly();
    }

    public function healthState(): ?HealthState
    {
        return HealthState::tryFrom($this->health);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'enabled' => 'boolean',
            'writes_enabled' => 'boolean',
            'supported' => 'boolean',
            'health_checked_at' => 'immutable_datetime',
            'last_collected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
