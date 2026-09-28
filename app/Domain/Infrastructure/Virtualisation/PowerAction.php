<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Virtualisation;

/**
 * What this platform may do to a machine's power (§10).
 *
 * **Four members, and the two ways of stopping a machine are not the same
 * thing.** `Shutdown` asks the operating system to stop and is what an
 * operator means nine times in ten; `PowerOff` cuts the power and is what
 * they mean when the operating system has stopped listening. A scale that
 * offered one word would make somebody choose the wrong one at three in the
 * morning — and one of those two loses whatever was not written to disk.
 *
 * **There is deliberately no `Reset`.** A reset is a power-off and a
 * power-on with no pause between them, and an operator who wants that can
 * press two buttons and see the machine stop in between. A single button
 * that did both would hide the moment at which somebody could still change
 * their mind.
 *
 * Nothing here is a snapshot, a migration or a console. Those are writes with
 * their own consequences and their own capabilities, and none of them has a
 * method on a contract yet.
 */
enum PowerAction: string
{
    case Start = 'start';

    /** Ask the operating system to stop. What an operator means nine times in ten. */
    case Shutdown = 'shutdown';

    /** Cut the power. What they mean when the operating system has stopped listening. */
    case PowerOff = 'power_off';

    /** Ask the operating system to restart. */
    case Reboot = 'reboot';

    public function labelKey(): string
    {
        return 'infrastructure.virtualisation.power.'.$this->value;
    }

    /**
     * Whether this one can lose work.
     *
     * The two that cut power or restart without asking are the level-4
     * confirmations; starting a machine that is already off cannot destroy
     * anything and does not need somebody to type its name out.
     */
    public function isAbrupt(): bool
    {
        return $this === self::PowerOff;
    }

    /** Whether it makes a running machine stop serving. */
    public function stopsService(): bool
    {
        return $this !== self::Start;
    }
}
