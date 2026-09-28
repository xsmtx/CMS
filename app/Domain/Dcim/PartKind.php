<?php

declare(strict_types=1);

namespace App\Domain\Dcim;

/**
 * What kind of thing a spare part is (§11).
 *
 * **A closed list, unlike `ResourceKind`**, and the difference is what each
 * is for: a graph node's kind is an open vocabulary because a module will
 * discover hardware core has never heard of, and a part is something an
 * operator types on a form. A dropdown of every noun anybody has ever used is
 * a dropdown nobody can find anything in.
 *
 * `Other` is the escape, and it is deliberately the only one: a part whose
 * kind is not here is still worth recording with its serial and its warranty,
 * which is the whole point of the table. What it must not do is quietly
 * become a new member — that is somebody's decision, made once, in code.
 */
enum PartKind: string
{
    case Disk = 'disk';
    case Memory = 'memory';
    case Cpu = 'cpu';
    case PowerSupply = 'power_supply';
    case NetworkCard = 'network_card';

    /** An SFP, a QSFP, a DAC. Small, expensive and the first thing to go missing. */
    case Optic = 'optic';

    case Other = 'other';

    public function labelKey(): string
    {
        return 'dcim.parts.kinds.'.$this->value;
    }
}
