<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Power\PowerOutlet;

/**
 * Something that knows what is plugged into it (§11).
 *
 * An APC or Vertiv NMC, a Server Technology PDU, a Raritan, an Eaton.
 *
 * **It reads and never switches.** `Capability::PduOutletWrite` exists and
 * has no method here — the sixth time that pattern appears. Switching an
 * outlet cuts the power to whatever is plugged into it, with none of the
 * warning a hypervisor's shutdown gives, and it belongs behind a guarded
 * workflow rather than behind a method anything could call. It is also the
 * one write in this product where the platform would not be able to tell
 * afterwards what it had done: a machine that does not come back looks
 * exactly like a machine that was never on.
 *
 * **A read that failed must throw, never return `[]`.** An empty answer means
 * the PDU has no outlets, which retires the whole rack's power path.
 */
interface PowerProvider extends InfrastructureAdapter
{
    /**
     * Every socket this PDU has.
     *
     * @return list<PowerOutlet>
     */
    public function outlets(): array;
}
