<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\RateLimits;

/**
 * What every adapter answers before anything else is asked of it.
 *
 * Five methods, and none of them touch the remote system except `health()`.
 * That is deliberate: the registry builds its screen from `key`, `name`,
 * `vendor`, `capabilities` and `limits` without a single network call, so an
 * operator can look at what an adapter *would* do while the device is
 * unreachable — which is precisely when they are looking.
 *
 * The capability contracts (`MonitoringProvider`, and twenty-two more arriving
 * with their phases) extend this one. An adapter implements as many as it
 * honestly supports, and `capabilities()` is how it says which.
 *
 * Nothing here is a write. An interface that mixed "tell me what you can do"
 * with "do it" would be an interface a read-only installation still had to
 * implement.
 */
interface InfrastructureAdapter
{
    /**
     * Stable identity, and it is the adapter's rather than the module's.
     *
     * One package can provide three adapters — a vendor SDK that speaks to
     * firewalls, switches and routers is one package — and the operator's
     * decision to allow writes is about one of them.
     */
    public function key(): string;

    public function name(): string;

    /** Who makes the thing on the other end. `Prometheus`, `Fortinet`, `Veeam`. */
    public function vendor(): string;

    public function capabilities(): CapabilitySet;

    /**
     * How hard this adapter may be pushed. Core does the waiting.
     */
    public function limits(): RateLimits;

    /**
     * Whether the remote is reachable and speaking a version this understands.
     *
     * **Returns no configuration.** Not a host, not a DSN, not a token prefix
     * (the Phase 9 rule, and a test asserts it). It is rendered on a screen and
     * written to a log.
     *
     * Throwing is allowed and is treated as `failing` with a redacted message —
     * an adapter author should not have to write a try/catch to report that a
     * device is down.
     */
    public function health(): AdapterHealth;
}
