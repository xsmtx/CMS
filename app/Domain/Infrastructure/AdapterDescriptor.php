<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * What an adapter says about itself before anybody calls it.
 *
 * Deliberately a flat value object with no behaviour: it is what the registry
 * stores on a row and what the Adapters screen renders, and both of those have
 * to work for an adapter whose package is not currently loaded. The row is the
 * record; the object is how it travels.
 *
 * `key` is the identity and it is the adapter's own, not the module's. One
 * module can provide three adapters — a vendor SDK that speaks to firewalls,
 * switches and routers is one package — and an operator enabling writes is
 * making a decision about one of them.
 */
final readonly class AdapterDescriptor
{
    public function __construct(
        /** Stable, lowercase, dotted at most once: `prometheus`, `fortigate.firewall`. */
        public string $key,
        /** What an operator calls it. */
        public string $name,
        /** Who makes the thing on the other end: `Prometheus`, `Fortinet`. */
        public string $vendor,
        public CapabilitySet $capabilities,
        public RateLimits $limits,
        /** The module that provided it, or null when core did. */
        public ?string $module = null,
        /** The adapter's own version, which is the package's rather than the remote's. */
        public ?string $version = null,
    ) {}

    /**
     * @return list<AdapterArea>
     */
    public function areas(): array
    {
        return $this->capabilities->areas();
    }

    /**
     * The same descriptor with every write capability removed.
     *
     * What the registry hands out for an adapter whose row has not had writes
     * enabled. Narrowing here rather than refusing at the call site is what
     * makes it impossible for a screen to render a button the platform would
     * then refuse.
     */
    public function readOnly(): self
    {
        return new self(
            $this->key,
            $this->name,
            $this->vendor,
            $this->capabilities->readOnly(),
            $this->limits,
            $this->module,
            $this->version,
        );
    }
}
