<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * What a scored placement looks at, and how much each answer is worth.
 *
 * §4 lists thirteen inputs. This is the subset an installation can actually
 * know something about, and the omissions are deliberate rather than pending:
 * **reserved capacity** and **compatibility** are not scored, because a server
 * group already decides which nodes can take a product and there is no column
 * anywhere that reserves a slot — a factor scored from a number nobody entered
 * is a factor that always says the same thing. Maintenance is not here either,
 * because it is a refusal rather than a score: `PlaceService` never offers a
 * node an operator has taken out of service.
 *
 * **A factor may decline.** `null` from a factor means "nothing has ever
 * reported this", and the total is the weighted mean of the factors that
 * answered — so a node nothing monitors is neither rewarded nor punished for
 * it. Scoring absence as zero would send every service to the one box the
 * monitoring system forgot, and scoring it as one would send them to a box
 * nobody can see. Both are how a placement engine quietly becomes the worst
 * possible one.
 *
 * The weights are ordinary judgement and an operator can read them off the
 * screen: disk pressure outranks account count because a full disk is the
 * failure that cannot be undone by moving a process, and health outranks
 * everything measurable because a node already in trouble should not be given
 * more work whatever its numbers say.
 */
enum PlacementFactor: string
{
    /** How full the node is, in accounts per unit of operator weight. */
    case Accounts = 'accounts';

    case Cpu = 'cpu';

    case Memory = 'memory';

    case Disk = 'disk';

    /** Disk operations, relative to the busiest candidate. */
    case Io = 'io';

    /** Bytes out, relative to the busiest candidate. */
    case Bandwidth = 'bandwidth';

    /** What the graph last said about the node itself. */
    case Health = 'health';

    /** Whether the node is where the customer is. */
    case Region = 'region';

    /** Where the daily series says this node is heading. */
    case Growth = 'growth';

    /** How much of this customer is already on this one node. */
    case AntiAffinity = 'anti_affinity';

    public function labelKey(): string
    {
        return 'provisioning.placement.factors.'.$this->value;
    }

    /**
     * How much this factor counts, relative to the others.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Disk, self::Health => 4,
            self::Cpu, self::Memory => 3,
            self::Accounts, self::Region, self::Growth, self::AntiAffinity => 2,
            self::Io, self::Bandwidth => 1,
        };
    }

    /**
     * Whether this factor is a reading from a monitoring system.
     *
     * The screen says so, because "the disk is 4% used" and "the group is set
     * to prefer this region" are different kinds of claim and an operator
     * deciding whether to trust a placement needs to know which is which.
     */
    public function isMeasured(): bool
    {
        return match ($this) {
            self::Cpu, self::Memory, self::Disk, self::Io, self::Bandwidth, self::Growth => true,
            self::Accounts, self::Health, self::Region, self::AntiAffinity => false,
        };
    }
}
