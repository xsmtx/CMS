<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * A VLAN as a switch reports it, which is not the same thing as a VLAN row.
 *
 * `App\Infrastructure\Network\Models\Vlan` is what an operator typed into this
 * platform; this is what a device says it actually has. They are deliberately
 * separate types, because the whole value of reading a switch is being able to
 * say they disagree - a VLAN configured here and missing on the box, or one on
 * the box that nobody recorded, is exactly the finding somebody wants.
 *
 * Merging the two into one class would make that comparison impossible to
 * express and would put a device's answer in the same shape as a record of
 * intent, which is the mistake ADR 0043 named for the graph: never store the
 * facts the owning table holds, and never let a discovered fact look like a
 * declared one.
 */
final readonly class VlanDescriptor
{
    /**
     * @param  list<string>  $ports  Port names carrying this VLAN, as the
     *                               device names them.
     */
    public function __construct(
        public int $tag,
        public ?string $name = null,
        public ?string $description = null,
        public array $ports = [],
    ) {}
}
