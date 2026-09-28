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
 *
 * **The sentence an operator reads is a key, not the message.** The message
 * is English and belongs in a log; the screen renders `key()` with
 * `replacements()`. These shipped as hard-coded English in the DCIM spine and
 * were the only untranslated refusals in the product.
 */
final class RackRefused extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replacements
     */
    private function __construct(
        string $message,
        private readonly string $key,
        private readonly array $replacements = [],
    ) {
        parent::__construct($message);
    }

    public static function doesNotFit(string $rack, int $startUnit, int $height, int $units): self
    {
        return new self(
            'A '.$height.'U device at unit '.$startUnit.' would end above the top of '
            .$rack.', which is '.$units.'U.',
            'dcim.rack.errors.does_not_fit',
            ['rack' => $rack, 'start' => $startUnit, 'height' => $height, 'units' => $units],
        );
    }

    public static function overlaps(string $device, int $from, int $to): self
    {
        return $device === ''
            ? new self(
                'Units '.$from.' to '.$to.' are already taken.',
                'dcim.rack.errors.overlaps_unnamed',
                ['from' => $from, 'to' => $to],
            )
            : new self(
                $device.' is already in units '.$from.' to '.$to.'.',
                'dcim.rack.errors.overlaps',
                ['device' => $device, 'from' => $from, 'to' => $to],
            );
    }

    public static function occupied(string $rack, int $startUnit): self
    {
        return new self(
            'Unit '.$startUnit.' of '.$rack.' was taken while this was being saved.',
            'dcim.rack.errors.occupied',
            ['rack' => $rack, 'start' => $startUnit],
        );
    }

    public static function badHeight(): self
    {
        return new self('A device occupies at least one unit.', 'dcim.rack.errors.bad_height');
    }

    public static function nothingToPlace(): self
    {
        return new self(
            'Choose a server, or give this position a name.',
            'dcim.rack.errors.nothing_to_place',
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, string|int>
     */
    public function replacements(): array
    {
        return $this->replacements;
    }
}
