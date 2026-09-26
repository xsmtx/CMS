<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * What a port on a device is doing, in the three words every vendor agrees on.
 *
 * `Down` and `Disabled` are separate because they mean opposite things to an
 * operator: a port that is down is a cable, an optic or the machine at the
 * other end, and a port that is disabled is a decision somebody made. A
 * platform that collapsed the two would show a rack of failures on the night
 * somebody shut eight unused ports.
 *
 * `Unknown` is here for the same reason availability is three-valued in ADR
 * 0028: a device that did not report a port's state has **not** said it is
 * down.
 */
enum PortState: string
{
    case Up = 'up';
    case Down = 'down';
    case Disabled = 'disabled';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.ports.'.$this->value;
    }
}
