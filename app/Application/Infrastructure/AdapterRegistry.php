<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\AdapterDescriptor;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Infrastructure\RateLimits;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Which adapters exist, what they may do here, and where the row is.
 *
 * The one place that answers "may this adapter do this", and it answers from the
 * **row** rather than from the package. An adapter declares what it can do; the
 * installation decides what it may. So the same FortiGate package is a read-only
 * window on one installation and a control plane on another, by the operator's
 * choice rather than by which package they installed — which is the rule in
 * `advanced-operations-plan.md` §5 that earns the most.
 *
 * **A missing row is created on read, defaulting to read-only.** That is a write
 * on a GET and it is the right trade: the alternative is writing rows when a
 * module is enabled, which misses an adapter added by a module *update* — the
 * common case, since a vendor adapter grows capabilities over its life. Nothing
 * is implied by the row existing: `writes_enabled` is false, and turning it on is
 * a separate, audited decision.
 */
final readonly class AdapterRegistry
{
    public function __construct(private ActiveModules $modules) {}

    /**
     * Every adapter the running modules provide, with this installation's
     * decisions already applied.
     *
     * @return list<RegisteredAdapter>
     */
    public function all(string $organizationId): array
    {
        $registered = [];

        foreach ($this->discover() as [$adapter, $moduleSlug]) {
            $descriptor = $this->describe($adapter, $moduleSlug);

            $registered[] = new RegisteredAdapter(
                $descriptor,
                $this->rowFor($organizationId, $descriptor),
                $adapter,
            );
        }

        return $registered;
    }

    /**
     * One adapter by key, or null when no running module provides it.
     */
    public function find(string $organizationId, string $key): ?RegisteredAdapter
    {
        foreach ($this->all($organizationId) as $registered) {
            if ($registered->descriptor->key === $key) {
                return $registered;
            }
        }

        return null;
    }

    /**
     * Whether this adapter may be asked to do this, here, now.
     *
     * Asked before the call, never caught after it. A capability the row has not
     * enabled is not refused at the moment of use — it is absent, so a screen
     * cannot offer a button that would then be refused.
     */
    public function permits(string $organizationId, string $key, Capability $capability): bool
    {
        $registered = $this->find($organizationId, $key);

        return $registered?->permitted()->has($capability) ?? false;
    }

    /**
     * The rows this installation holds for adapters no running module provides.
     *
     * Shown on the screen rather than hidden: a module disabled last week leaves
     * a row that says an operator once allowed writes on it, and that is worth
     * seeing before the module is enabled again.
     *
     * @return list<ResourceAdapter>
     */
    public function orphaned(string $organizationId): array
    {
        $known = array_keys($this->discover());

        return array_values(
            ResourceAdapter::query()
                ->where('organization_id', $organizationId)
                ->when(
                    $known !== [],
                    static fn (Builder $query): Builder => $query->whereNotIn('adapter_key', $known),
                )
                ->orderBy('adapter_key')
                ->get()
                ->all()
        );
    }

    /**
     * @return array<string, array{0: InfrastructureAdapter, 1: string|null}>
     */
    private function discover(): array
    {
        $found = [];

        foreach ($this->modules->adaptersBySlug() as $slug => $adapters) {
            foreach ($adapters as $adapter) {
                // First one wins, so a second module claiming a key cannot
                // silently replace the first. `InspectModule` is where a clash
                // becomes visible to an operator.
                $found[$adapter->key()] ??= [$adapter, $slug];
            }
        }

        return $found;
    }

    private function describe(InfrastructureAdapter $adapter, ?string $moduleSlug): AdapterDescriptor
    {
        try {
            $capabilities = $adapter->capabilities();
            $limits = $adapter->limits();
        } catch (Throwable) {
            // An adapter that cannot even describe itself is listed as providing
            // nothing rather than taking the screen down. One bad package must
            // not be the end of the installation (ADR 0038).
            $capabilities = CapabilitySet::of([]);
            $limits = RateLimits::unlimited();
        }

        return new AdapterDescriptor(
            key: $adapter->key(),
            name: $adapter->name(),
            vendor: $adapter->vendor(),
            capabilities: $capabilities,
            limits: $limits,
            module: $moduleSlug,
        );
    }

    private function rowFor(string $organizationId, AdapterDescriptor $descriptor): ResourceAdapter
    {
        $row = ResourceAdapter::query()
            ->where('organization_id', $organizationId)
            ->where('adapter_key', $descriptor->key)
            ->first();

        $declared = $descriptor->capabilities->toValues();

        if ($row instanceof ResourceAdapter) {
            // Keep the stored declaration in step with the package, so the screen
            // is right after a module update. The operator's columns —
            // `enabled`, `writes_enabled` — are never touched here.
            if ($row->capabilities !== $declared || $row->name !== $descriptor->name) {
                $row->capabilities = $declared;
                $row->name = $descriptor->name;
                $row->vendor = $descriptor->vendor;
                $row->module = $descriptor->module;
                $row->save();
            }

            return $row;
        }

        return ResourceAdapter::query()->create([
            'organization_id' => $organizationId,
            'adapter_key' => $descriptor->key,
            'name' => $descriptor->name,
            'vendor' => $descriptor->vendor,
            'module' => $descriptor->module,
            'capabilities' => $declared,
            'enabled' => true,
            'writes_enabled' => false,
        ]);
    }
}
