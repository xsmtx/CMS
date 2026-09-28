<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Virtualisation\HypervisorHost;
use App\Domain\Infrastructure\Virtualisation\VirtualMachine;

/**
 * Something that knows what it is running (§10).
 *
 * Proxmox, vSphere, Hyper-V, XCP-ng, OpenStack.
 *
 * **It reads and never provisions.** `Capability::VirtualMachineConfigWrite`,
 * `SnapshotWrite` and `MigrationWrite` exist and have no method here — the
 * fifth time that pattern appears. Creating a machine is provisioning, which
 * this product already does through a different seam, and a second answer to
 * it would be two places that disagree about what a customer bought.
 * `VirtualMachinePowerWrite` is the exception and it is `HypervisorWriter`.
 *
 * **A read that failed must throw, never return `[]`.** An empty answer is
 * taken literally — every machine this hypervisor had has gone — so a
 * cluster that is merely unreachable would retire the whole fleet. The rule
 * `BackupProvider`, `StorageProvider` and `LoadBalancerProvider` all state.
 */
interface HypervisorProvider extends InfrastructureAdapter
{
    /**
     * Every host this source knows about.
     *
     * @return list<HypervisorHost>
     */
    public function hosts(): array;

    /**
     * Every machine, across every host.
     *
     * @return list<VirtualMachine>
     */
    public function machines(): array;

    /**
     * One machine as it is *now*.
     *
     * Asked immediately after a power action, because the whole point of the
     * action is the state it produces — and a hypervisor that accepted a
     * shutdown and did nothing is the failure worth catching.
     */
    public function machine(string $key): ?VirtualMachine;
}
