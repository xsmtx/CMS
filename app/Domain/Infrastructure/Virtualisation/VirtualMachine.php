<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Virtualisation;

/**
 * One machine a hypervisor is running, as it describes it (§10).
 *
 * **`hostKey` is what makes the graph worth having here.** A VM on its own is
 * something the hypervisor's own console already shows; a VM known to be on
 * *this* host, which is in *that* rack, behind *that* switch, is the
 * containment walk this platform exists to do — and it is what answers
 * “what goes down if I reboot hv-3” before somebody finds out.
 *
 * **Every figure is nullable and null means the hypervisor did not say.** A
 * machine that reports no memory has not reported zero memory; a zero on a
 * capacity screen is a machine that looks free to fill.
 *
 * Nothing here is a console ticket, a VNC password or a migration token.
 * Core reads what is running; the console is §17's just-in-time access and
 * needs none of this.
 */
final readonly class VirtualMachine
{
    public function __construct(
        /** The hypervisor's own identifier, stable across runs. */
        public string $key,
        public string $name,
        public MachineState $state = MachineState::Unknown,
        /** The host it is on, in the hypervisor's own words. */
        public ?string $hostKey = null,
        public ?int $vcpus = null,
        public ?int $memoryBytes = null,
        public ?int $diskBytes = null,
        public ?int $uptimeSeconds = null,
        /** `qemu`, `lxc`, `vm` — the hypervisor's own word. */
        public ?string $kind = null,
    ) {}
}
