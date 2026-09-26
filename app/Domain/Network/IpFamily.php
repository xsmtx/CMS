<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * Which of the two internets an address belongs to.
 *
 * Kept as a field rather than derived from the bytes, because every IPv4
 * address is stored in the IPv6 space (`IpAddress`) and `::ffff:192.0.2.1`
 * written out in full is a v6 address that happens to have the same sixteen
 * bytes. What an operator typed is what this says.
 */
enum IpFamily: string
{
    case V4 = 'v4';
    case V6 = 'v6';

    /**
     * How many bits an address of this family actually has.
     *
     * The storage is always 128 bits wide; this is the number a prefix length
     * is written in, so `/24` means 24 and not 120.
     */
    public function bits(): int
    {
        return $this === self::V4 ? 32 : 128;
    }

    /**
     * Where this family starts inside the 128-bit space everything is stored
     * in. IPv4 lives at the end of it, mapped, so one column and one index
     * order both families.
     */
    public function offset(): int
    {
        return 128 - $this->bits();
    }

    public function labelKey(): string
    {
        return 'network.families.'.$this->value;
    }
}
