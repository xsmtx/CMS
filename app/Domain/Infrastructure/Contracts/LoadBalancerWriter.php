<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

/**
 * A load balancer this platform may take a backend out of (§9, §16).
 *
 * **The one write in the storage-and-balancer family that ships with a
 * method**, and the plan says why: a drain is reversible and a firewall
 * policy is not. Requiring a second person to approve a drain would make a
 * maintenance window need somebody else awake at two in the morning, so the
 * gate is a permission plus the password challenge rather than an approval —
 * a guarded action, not a guarded change.
 *
 * **It is idempotent, and that is what makes retrying it safe.** Draining a
 * backend that is already draining is a success, exactly as `already_done`
 * is for provisioning (ADR 0026). An adapter that refused the second call
 * would make every retry an incident.
 *
 * **Neither method waits.** Telling a balancer to stop sending new
 * connections takes a second; waiting for the connections it already has to
 * finish is the operator's job, and a method that blocked until the count
 * reached zero would be a request that hung for an hour and a queue worker
 * nobody could reclaim. `backend()` is how the screen shows the count coming
 * down.
 *
 * Separate from `LoadBalancerProvider` so an adapter that can only read says
 * so by not implementing it — the reason `NetworkDeviceWriter` is its own
 * interface.
 */
interface LoadBalancerWriter extends LoadBalancerProvider
{
    /**
     * Stop sending new connections to this backend.
     *
     * Existing connections are left to finish. A backend that is already
     * draining is not an error.
     */
    public function drain(string $listenerKey, string $backendKey): void;

    /**
     * Put it back into the rotation.
     */
    public function undrain(string $listenerKey, string $backendKey): void;
}
