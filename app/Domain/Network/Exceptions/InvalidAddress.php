<?php

declare(strict_types=1);

namespace App\Domain\Network\Exceptions;

use InvalidArgumentException;

/**
 * Something that is not an address, or not a prefix.
 *
 * The value is quoted back, which is safe here and deliberate: an operator who
 * typed `192.0.2.256` needs to see what was read. Nothing in this area is a
 * secret — an address is the most public fact a machine has.
 */
final class InvalidAddress extends InvalidArgumentException
{
    public static function address(string $value): self
    {
        return new self(sprintf('"%s" is not an IP address.', $value));
    }

    public static function prefix(string $value): self
    {
        return new self(sprintf('"%s" is not a CIDR prefix.', $value));
    }

    public static function prefixLength(int $length, string $family): self
    {
        return new self(sprintf('A /%d is not a valid %s prefix length.', $length, $family));
    }

    /**
     * A prefix whose address has bits set below its own length.
     *
     * `192.0.2.5/24` is somebody's address written where a network was wanted,
     * and accepting it silently would file it under the wrong network.
     */
    public static function notANetwork(string $value, string $network): self
    {
        return new self(sprintf('"%s" has host bits set; its network is %s.', $value, $network));
    }

    public static function familyMismatch(): self
    {
        return new self('An IPv4 address cannot be compared with an IPv6 prefix.');
    }
}
