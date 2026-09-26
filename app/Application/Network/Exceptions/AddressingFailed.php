<?php

declare(strict_types=1);

namespace App\Application\Network\Exceptions;

use RuntimeException;

/**
 * Why an address could not be handed out, taken back or filed.
 *
 * Every reason is named separately rather than collapsed into one sentence, for
 * the reason the package refusals are: "it did not work" makes a full prefix and
 * a double assignment look identical in an audit log, and they call for opposite
 * actions.
 */
final class AddressingFailed extends RuntimeException
{
    public static function prefixFull(string $cidr): self
    {
        return new self(sprintf('%s has no free addresses left.', $cidr));
    }

    public static function outsidePrefix(string $address, string $cidr): self
    {
        return new self(sprintf('%s is not inside %s.', $address, $cidr));
    }

    public static function alreadyAssigned(string $address): self
    {
        return new self(sprintf('%s is already assigned.', $address));
    }

    public static function notAssigned(string $address): self
    {
        return new self(sprintf('%s is not assigned to anything.', $address));
    }

    /**
     * Reserved and quarantined addresses are refused by name, because the two
     * mean different things to whoever is being refused: one is a decision
     * somebody made and the other is an address waiting out its reputation.
     */
    public static function notAllocatable(string $address, string $state): self
    {
        return new self(sprintf('%s cannot be handed out while it is %s.', $address, $state));
    }

    public static function overlapping(string $cidr, string $existing): self
    {
        return new self(sprintf('%s overlaps %s, which already exists.', $cidr, $existing));
    }

    public static function prefixInUse(string $cidr, int $addresses): self
    {
        return new self(sprintf('%s still holds %d addresses.', $cidr, $addresses));
    }

    public static function familyMismatch(string $cidr, string $pool): self
    {
        return new self(sprintf('%s does not belong in a %s pool.', $cidr, $pool));
    }
}
