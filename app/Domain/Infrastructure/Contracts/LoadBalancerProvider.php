<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\LoadBalancing\Backend;
use App\Domain\Infrastructure\LoadBalancing\Listener;

/**
 * Something that knows what it is balancing (§9).
 *
 * HAProxy, Nginx, Traefik, Envoy, F5, Citrix ADC.
 *
 * **A read that failed must throw, never return `[]`.** An empty answer is
 * taken literally — every listener this balancer had has gone — so a balancer
 * that is merely unreachable would retire the whole estate. The same rule
 * `BackupProvider` and `StorageProvider` state.
 *
 * The write half is `LoadBalancerWriter`, and it is a separate interface for
 * the reason `NetworkDeviceWriter` is: an adapter that can only read must be
 * able to say so by not implementing it, and a registry that has to ask
 * "does this method throw" is a registry that offers buttons the platform
 * then refuses.
 */
interface LoadBalancerProvider extends InfrastructureAdapter
{
    /**
     * Everything this balancer is listening on, with what is behind each.
     *
     * @return list<Listener>
     */
    public function listeners(): array;

    /**
     * One backend as it is *now*.
     *
     * Asked immediately after a drain or an undrain, because the whole point
     * of the action is the state it produces and a screen that showed the
     * state from the last hourly sweep would tell the operator the drain had
     * not worked. It is also the only honest way to report a balancer that
     * accepted the command and did nothing.
     */
    public function backend(string $listenerKey, string $backendKey): ?Backend;
}
