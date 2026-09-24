<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Capability;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Letting an adapter change things, or taking that back.
 *
 * A use case of its own rather than a column on a settings form, for one reason:
 * it is the moment this installation stops being a window and starts being a
 * control plane. That deserves an audit row with a name on it, and the row names
 * the capabilities as well as the adapter — "allowed writes on fortigate" is not
 * enough six months later, and "allowed firewall.policy.write and
 * network_device.config.write" is.
 *
 * Disabling is never refused and never made to hesitate: switching an adapter off
 * is what an operator does when something is going wrong, and a guard in front of
 * it would be a guard that made an outage longer.
 */
final readonly class SetAdapterWrites
{
    public function handle(
        ResourceAdapter $adapter,
        bool $writesEnabled,
        ?Model $actor = null,
        ?string $reason = null,
    ): ResourceAdapter {
        if ($adapter->writes_enabled === $writesEnabled) {
            return $adapter;
        }

        $adapter->writes_enabled = $writesEnabled;
        $adapter->save();

        Audit::action($writesEnabled
                ? 'infrastructure.adapter.writes_allowed'
                : 'infrastructure.adapter.writes_revoked')
            ->by($actor)
            ->on($adapter)
            ->forOrganization($adapter->organization_id)
            ->changed(['writes_enabled' => ! $writesEnabled], ['writes_enabled' => $writesEnabled])
            ->withMetadata(['capabilities' => $this->writeCapabilitiesOf($adapter)])
            ->because($reason)
            ->write();

        return $adapter;
    }

    public function setEnabled(ResourceAdapter $adapter, bool $enabled, ?Model $actor = null): ResourceAdapter
    {
        if ($adapter->enabled === $enabled) {
            return $adapter;
        }

        $adapter->enabled = $enabled;
        $adapter->save();

        Audit::action($enabled ? 'infrastructure.adapter.enabled' : 'infrastructure.adapter.disabled')
            ->by($actor)
            ->on($adapter)
            ->forOrganization($adapter->organization_id)
            ->write();

        return $adapter;
    }

    /**
     * Exactly what has just been allowed, by name.
     *
     * @return list<string>
     */
    private function writeCapabilitiesOf(ResourceAdapter $adapter): array
    {
        return array_map(
            static fn (Capability $capability): string => $capability->value,
            $adapter->declaredCapabilities()->writes(),
        );
    }
}
