<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Network\Exceptions\InvalidAddress;
use JsonSerializable;
use Stringable;

/**
 * One IP address, stored as bytes and read as text.
 *
 * **Sixteen bytes, always.** IPv4 is mapped into the IPv6 space
 * (`::ffff:a.b.c.d`), so one column, one index and one comparison answer both
 * families — and "is this inside that prefix", "what is the next free one" and
 * "sort these" become range questions a database can do rather than a loop PHP
 * has to do.
 *
 * **The text is derived from the bytes, never kept alongside them.**
 * `2001:db8::1` and `2001:0db8:0000:0000:0000:0000:0000:0001` are one address
 * written two ways, and a platform that stored what was typed would hold two
 * rows for it, assign it twice, and be unable to say who had it. `inet_ntop`
 * decides what it is called; an operator's spelling decides nothing.
 *
 * The family is carried rather than inferred, because `::ffff:192.0.2.1` is a
 * v6 address with a v4 address's bytes.
 */
final readonly class IpAddress implements JsonSerializable, Stringable
{
    /** The IPv4-mapped IPv6 prefix: ten zero bytes then two 0xff. */
    private const string V4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    private function __construct(
        /** Sixteen bytes, big-endian. Binary, never text. */
        public string $bytes,
        public IpFamily $family,
    ) {}

    public function __toString(): string
    {
        return $this->text();
    }

    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidAddress::address($value);
        }

        $packed = @inet_pton($trimmed);

        if ($packed === false) {
            throw InvalidAddress::address($value);
        }

        return strlen($packed) === 4
            ? new self(self::V4_PREFIX.$packed, IpFamily::V4)
            : new self($packed, IpFamily::V6);
    }

    /**
     * Rebuild from what the database holds.
     *
     * The family is stored beside the bytes for the reason in the class
     * comment, so this takes both rather than guessing at one.
     */
    public static function fromBytes(string $bytes, IpFamily $family): self
    {
        if (strlen($bytes) !== 16) {
            throw InvalidAddress::address(bin2hex($bytes));
        }

        return new self($bytes, $family);
    }

    public function text(): string
    {
        $packed = $this->family === IpFamily::V4 ? substr($this->bytes, 12, 4) : $this->bytes;

        $text = inet_ntop($packed);

        // Sixteen bytes is always a valid address, so this cannot fail - but a
        // false here would otherwise become the string "" three layers away.
        if ($text === false) {
            throw InvalidAddress::address(bin2hex($this->bytes));
        }

        return $text;
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }

    /**
     * Byte-wise, which is numeric order: `strcmp` compares unsigned bytes and
     * the storage is big-endian, so 10.0.0.9 sorts before 10.0.0.10.
     */
    public function compare(self $other): int
    {
        return strcmp($this->bytes, $other->bytes) <=> 0;
    }

    /**
     * The next address, or null at the top of the space.
     *
     * Used to walk a prefix looking for a free address. Carries by hand over
     * sixteen bytes because no PHP integer is that wide.
     */
    public function next(): ?self
    {
        $bytes = $this->bytes;

        for ($i = 15; $i >= 0; $i--) {
            $byte = ord($bytes[$i]);

            if ($byte < 255) {
                $bytes[$i] = chr($byte + 1);

                return new self($bytes, $this->family);
            }

            $bytes[$i] = "\x00";
        }

        return null;
    }

    /**
     * This address with everything below `bits` cleared - the network it is in
     * at that length.
     *
     * `bits` is counted in the 128-bit storage space, so a caller holding a
     * family's own length adds `IpFamily::offset()` first.
     */
    public function maskedTo(int $bits): self
    {
        $bits = max(0, min(128, $bits));
        $bytes = $this->bytes;

        for ($i = 0; $i < 16; $i++) {
            $remaining = $bits - ($i * 8);

            if ($remaining >= 8) {
                continue;
            }

            $bytes[$i] = $remaining <= 0
                ? "\x00"
                : chr(ord($bytes[$i]) & (0xFF << (8 - $remaining)) & 0xFF);
        }

        return new self($bytes, $this->family);
    }

    /**
     * This address with everything below `bits` set - the top of that network.
     */
    public function filledFrom(int $bits): self
    {
        $bits = max(0, min(128, $bits));
        $bytes = $this->bytes;

        for ($i = 0; $i < 16; $i++) {
            $remaining = $bits - ($i * 8);

            if ($remaining >= 8) {
                continue;
            }

            $bytes[$i] = $remaining <= 0
                ? "\xff"
                : chr(ord($bytes[$i]) | (0xFF >> $remaining));
        }

        return new self($bytes, $this->family);
    }

    public function jsonSerialize(): string
    {
        return $this->text();
    }
}
