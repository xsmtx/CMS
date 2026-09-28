<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Virtualisation\PowerAction;

/**
 * A hypervisor this platform may change the power state on (§10).
 *
 * **The most destructive thing in this product**, and the plan says so: a
 * power action does not stop a customer's service, it stops the machine
 * several customers are on. It is a level-4 confirmation — the reason plus
 * the machine's own name typed out — which is the bar
 * `CompleteCancellation` already sets for terminating one service, applied to
 * something that takes down many.
 *
 * **A power action is never retried.** `tries = 1`, like
 * `ApplyNetworkChangeJob` and unlike everything else in this product: a
 * power-off that timed out may very well have happened, and a second attempt
 * after somebody brought the machine back is an outage this platform caused
 * twice. Provisioning retries because creating an account twice is harmless
 * (ADR 0026); nothing about this is harmless.
 *
 * Separate from `HypervisorProvider` so an adapter that can only read says so
 * by not implementing it — the reason `NetworkDeviceWriter` and
 * `LoadBalancerWriter` are their own interfaces.
 */
interface HypervisorWriter extends HypervisorProvider
{
    /**
     * Do this to that machine's power.
     *
     * **It does not wait.** A shutdown a guest is thinking about can take
     * minutes, and a method that blocked would be a request that hung. The
     * caller reads the machine back to see where it got to; the operator
     * refreshes.
     */
    public function power(string $key, PowerAction $action): void;
}
