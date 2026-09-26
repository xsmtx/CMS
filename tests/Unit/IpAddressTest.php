<?php

declare(strict_types=1);

use App\Domain\Network\Exceptions\InvalidAddress;
use App\Domain\Network\IpAddress;
use App\Domain\Network\IpFamily;
use App\Domain\Network\IpPrefix;

/**
 * Addressing arithmetic, which is the one part of IPAM that cannot be checked by
 * looking at a screen.
 */
it('stores every address in the same sixteen bytes', function (): void {
    $four = IpAddress::parse('192.0.2.1');
    $six = IpAddress::parse('2001:db8::1');

    expect(strlen($four->bytes))->toBe(16)
        ->and(strlen($six->bytes))->toBe(16)
        ->and($four->family)->toBe(IpFamily::V4)
        ->and($six->family)->toBe(IpFamily::V6);
});

/**
 * The reason the text is derived rather than kept: one address written two ways
 * would otherwise be two rows, assigned twice, with no way to say who had it.
 */
it('gives one address one spelling', function (): void {
    expect(IpAddress::parse('2001:0db8:0000:0000:0000:0000:0000:0001')->text())
        ->toBe('2001:db8::1')
        ->and(IpAddress::parse('2001:DB8::1')->text())->toBe('2001:db8::1');
});

it('writes an IPv4 address back as an IPv4 address', function (): void {
    expect(IpAddress::parse('192.0.2.1')->text())->toBe('192.0.2.1');
});

it('refuses something that is not an address', function (): void {
    expect(fn (): IpAddress => IpAddress::parse('192.0.2.256'))->toThrow(InvalidAddress::class)
        ->and(fn (): IpAddress => IpAddress::parse(''))->toThrow(InvalidAddress::class)
        ->and(fn (): IpAddress => IpAddress::parse('example.com'))->toThrow(InvalidAddress::class);
});

/**
 * Byte order is numeric order, which is the whole reason for the binary column:
 * as text, 10.0.0.10 sorts before 10.0.0.9.
 */
it('sorts numerically rather than alphabetically', function (): void {
    $nine = IpAddress::parse('10.0.0.9');
    $ten = IpAddress::parse('10.0.0.10');

    expect($nine->compare($ten))->toBe(-1)
        ->and($ten->compare($nine))->toBe(1)
        ->and($nine->compare($nine))->toBe(0);
});

it('walks to the next address, carrying across bytes', function (): void {
    expect(IpAddress::parse('10.0.0.255')->next()?->text())->toBe('10.0.1.0')
        ->and(IpAddress::parse('2001:db8::ffff')->next()?->text())->toBe('2001:db8::1:0');
});

it('has nowhere to go at the top of the space', function (): void {
    expect(IpAddress::parse('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')->next())->toBeNull();
});

it('reads a prefix and keeps the length in its own terms', function (): void {
    $prefix = IpPrefix::parse('192.0.2.0/24');

    expect($prefix->length)->toBe(24)
        // The storage is 128 bits wide, so a /24 of v4 is a /120 in it - and
        // that conversion lives in one place rather than in every query.
        ->and($prefix->storedLength())->toBe(120)
        ->and($prefix->text())->toBe('192.0.2.0/24');
});

/**
 * Host bits set is somebody's address typed where a network was wanted. Masking
 * it silently would file the address under a network nobody named.
 */
it('refuses a prefix with host bits set, and says what was meant', function (): void {
    expect(fn (): IpPrefix => IpPrefix::parse('192.0.2.5/24'))
        ->toThrow(InvalidAddress::class, '192.0.2.0/24');
});

it('refuses a prefix length the family does not have', function (): void {
    expect(fn (): IpPrefix => IpPrefix::parse('192.0.2.0/33'))->toThrow(InvalidAddress::class)
        ->and(fn (): IpPrefix => IpPrefix::parse('2001:db8::/129'))->toThrow(InvalidAddress::class)
        ->and(fn (): IpPrefix => IpPrefix::parse('192.0.2.0'))->toThrow(InvalidAddress::class);
});

it('knows what it contains', function (): void {
    $prefix = IpPrefix::parse('192.0.2.0/24');

    expect($prefix->contains(IpAddress::parse('192.0.2.1')))->toBeTrue()
        ->and($prefix->contains(IpAddress::parse('192.0.3.1')))->toBeFalse()
        // A v6 address is never inside a v4 prefix, whatever the bytes say.
        ->and($prefix->contains(IpAddress::parse('::ffff:192.0.2.1')))->toBeFalse();
});

it('knows which prefixes sit inside which', function (): void {
    $supernet = IpPrefix::parse('10.0.0.0/8');
    $subnet = IpPrefix::parse('10.1.0.0/16');
    $elsewhere = IpPrefix::parse('192.168.0.0/16');

    expect($supernet->containsPrefix($subnet))->toBeTrue()
        ->and($subnet->containsPrefix($supernet))->toBeFalse()
        ->and($supernet->overlaps($subnet))->toBeTrue()
        ->and($supernet->overlaps($elsewhere))->toBeFalse();
});

it('gives up the network and broadcast addresses, on IPv4 only', function (): void {
    [$first, $last] = IpPrefix::parse('192.0.2.0/24')->usableRange();

    expect($first->text())->toBe('192.0.2.1')
        ->and($last->text())->toBe('192.0.2.254');

    // A /31 is a point-to-point link with two usable addresses and no
    // broadcast (RFC 3021), and a /32 is one host.
    [$first, $last] = IpPrefix::parse('192.0.2.0/31')->usableRange();
    expect($first->text())->toBe('192.0.2.0')->and($last->text())->toBe('192.0.2.1');

    [$first, $last] = IpPrefix::parse('192.0.2.7/32')->usableRange();
    expect($first->text())->toBe('192.0.2.7')->and($last->text())->toBe('192.0.2.7');

    // IPv6 has no broadcast address, so nothing is subtracted.
    [$first, $last] = IpPrefix::parse('2001:db8::/126')->usableRange();
    expect($first->text())->toBe('2001:db8::')->and($last->text())->toBe('2001:db8::3');
});

/**
 * A /64 holds more addresses than a PHP integer, and a screen printing a wrapped
 * negative would be worse than one saying "too many to count".
 */
it('declines to count a range nobody should be shown', function (): void {
    expect(IpPrefix::parse('192.0.2.0/24')->addressCount())->toBe(256)
        ->and(IpPrefix::parse('192.0.2.0/32')->addressCount())->toBe(1)
        ->and(IpPrefix::parse('2001:db8::/64')->addressCount())->toBeNull();

    // And the count that matters on a screen is what can be handed out.
    expect(IpPrefix::parse('192.0.2.0/24')->usableCount())->toBe(254)
        ->and(IpPrefix::parse('192.0.2.0/31')->usableCount())->toBe(2)
        ->and(IpPrefix::parse('192.0.2.0/32')->usableCount())->toBe(1)
        ->and(IpPrefix::parse('2001:db8::/120')->usableCount())->toBe(256)
        ->and(IpPrefix::parse('2001:db8::/64')->usableCount())->toBeNull();
});

it('survives the round trip through the column it is stored in', function (): void {
    foreach (['192.0.2.1', '2001:db8::1', '::1', '0.0.0.0', '255.255.255.255'] as $text) {
        $address = IpAddress::parse($text);

        expect(IpAddress::fromBytes($address->bytes, $address->family)->text())->toBe($text);
    }
});
