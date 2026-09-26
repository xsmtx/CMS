<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Network\Exceptions\InvalidAddress;
use JsonSerializable;
use Stringable;

/**
 * A network, written the way operators write one: `192.0.2.0/24`.
 *
 * The length is in the family's own terms — a `/24` is a /24 and not a /120 —
 * and the conversion into the 128-bit storage space happens here rather than in
 * every caller.
 *
 * **Host bits set is a refusal, not a rounding.** `192.0.2.5/24` is somebody's
 * address typed where a network was wanted; accepting it and quietly masking it
 * would file an address under a network the operator did not name, and the
 * mistake would surface as a missing address months later. The exception says
 * what the network would have been, so correcting it is one keystroke.
 */
final readonly class IpPrefix implements JsonSerializable, Stringable
{
    private function __construct(
        public IpAddress $network,
        /** In the family's own terms: 0-32 for v4, 0-128 for v6. */
        public int $length,
        public IpFamily $family,
    ) {}

    public function __toString(): string
    {
        return $this->text();
    }

    public static function parse(string $value): self
    {
        $trimmed = trim($value);
        $parts = explode('/', $trimmed);

        if (count($parts) !== 2 || $parts[1] === '' || preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
            throw InvalidAddress::prefix($value);
        }

        $address = IpAddress::parse($parts[0]);
        $length = (int) $parts[1];

        if ($length > $address->family->bits()) {
            throw InvalidAddress::prefixLength($length, $address->family->value);
        }

        $network = $address->maskedTo($address->family->offset() + $length);

        if (! $network->equals($address)) {
            throw InvalidAddress::notANetwork($value, $network->text().'/'.$length);
        }

        return new self($network, $length, $address->family);
    }

    /**
     * Build without the host-bits refusal, for a value that is already a
     * network — a row this platform wrote, or an address the caller has
     * deliberately masked.
     */
    public static function of(IpAddress $address, int $length): self
    {
        if ($length < 0 || $length > $address->family->bits()) {
            throw InvalidAddress::prefixLength($length, $address->family->value);
        }

        return new self(
            $address->maskedTo($address->family->offset() + $length),
            $length,
            $address->family,
        );
    }

    public function text(): string
    {
        return $this->network->text().'/'.$this->length;
    }

    /** The prefix length counted in the 128-bit storage space. */
    public function storedLength(): int
    {
        return $this->family->offset() + $this->length;
    }

    public function firstAddress(): IpAddress
    {
        return $this->network;
    }

    public function lastAddress(): IpAddress
    {
        return $this->network->filledFrom($this->storedLength());
    }

    public function contains(IpAddress $address): bool
    {
        if ($address->family !== $this->family) {
            return false;
        }

        return $address->maskedTo($this->storedLength())->equals($this->network);
    }

    public function containsPrefix(self $other): bool
    {
        return $other->family === $this->family
            && $other->length >= $this->length
            && $this->contains($other->network);
    }

    public function overlaps(self $other): bool
    {
        return $this->containsPrefix($other) || $other->containsPrefix($this);
    }

    /**
     * The first address an operator can hand out, and the last.
     *
     * IPv4 gives up its network and broadcast addresses, which is what every
     * operator expects — except in a /31 and a /32, where there are none to
     * give up (RFC 3021 point-to-point links, and a single host). IPv6 has no
     * broadcast address at all, so nothing is subtracted; the subnet-router
     * anycast address at the bottom is a convention a host can be given and
     * this platform does not pretend otherwise.
     *
     * @return array{IpAddress, IpAddress}
     */
    public function usableRange(): array
    {
        if ($this->family === IpFamily::V6 || $this->length >= 31) {
            return [$this->firstAddress(), $this->lastAddress()];
        }

        $first = $this->firstAddress()->next();
        $last = $this->lastAddress();

        // The address below the broadcast: walk down by masking the broadcast's
        // own bottom bit, which is only correct because a v4 prefix shorter
        // than /31 always has at least four addresses in it.
        $previous = $this->addressBelow($last);

        return [$first ?? $this->firstAddress(), $previous];
    }

    /**
     * How many addresses are in it, or null when the answer is a number nobody
     * should be shown.
     *
     * A /64 holds 18,446,744,073,709,551,616 addresses. A screen that printed
     * that figure would be a screen an operator learns to ignore, and one that
     * printed it wrapped around to a negative integer would be worse — so the
     * question is declined above what a PHP integer holds, and the screen says
     * so in words.
     */
    public function addressCount(): ?int
    {
        $bits = $this->family->bits() - $this->length;

        return $bits >= 62 ? null : 1 << $bits;
    }

    /**
     * How many addresses can actually be handed out, or null when the answer
     * is a number nobody should be shown.
     *
     * The rule about what a family gives up lives next to the rule about what
     * the usable range is, rather than in whichever presenter needed it first.
     */
    public function usableCount(): ?int
    {
        $count = $this->addressCount();

        if ($count === null) {
            return null;
        }

        [$first, $last] = $this->usableRange();

        return $first->equals($this->firstAddress()) && $last->equals($this->lastAddress())
            ? $count
            : max(0, $count - 2);
    }

    public function jsonSerialize(): string
    {
        return $this->text();
    }

    /**
     * One below an address, by decrementing the sixteen bytes.
     */
    private function addressBelow(IpAddress $address): IpAddress
    {
        $bytes = $address->bytes;

        for ($i = 15; $i >= 0; $i--) {
            $byte = ord($bytes[$i]);

            if ($byte > 0) {
                $bytes[$i] = chr($byte - 1);

                return IpAddress::fromBytes($bytes, $address->family);
            }

            $bytes[$i] = "\xff";
        }

        return $address;
    }
}
