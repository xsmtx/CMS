<?php

declare(strict_types=1);

namespace App\Domain\Dcim\Exceptions;

use RuntimeException;

/**
 * Something that could not be put in a rack (§11).
 *
 * **Every reason named separately**, the rule `PackageRefused` set. A rack
 * diagram that does not match the building is worse than none, because
 * somebody will send a technician to the wrong cabinet — so the refusals say
 * exactly what is in the way and where, rather than "that does not fit".
 *
 * `doesNotFit` names the rack's own height, because the operator who typed 41
 * into a 42U rack is about to type 39 and wants to know that is enough.
 */
final class RackRefused extends RuntimeException
{
    public static function doesNotFit(string $rack, int $startUnit, int $height, int $units): self
    {
        return new self(
            'A '.$height.'U device at unit '.$startUnit.' would end above the top of '
            .$rack.', which is '.$units.'U.',
        );
    }

    public static function overlaps(string $device, int $from, int $to): self
    {
        return new self(
            $device === ''
                ? 'Units '.$from.' to '.$to.' are already taken.'
                : $device.' is already in units '.$from.' to '.$to.'.',
        );
    }

    public static function occupied(string $rack, int $startUnit): self
    {
        return new self('Unit '.$startUnit.' of '.$rack.' was taken while this was being saved.');
    }

    public static function badHeight(): self
    {
        return new self('A device occupies at least one unit.');
    }

    public static function nothingToPlace(): self
    {
        return new self('Choose a server, or give this position a name.');
    }
}
