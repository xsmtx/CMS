<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * A panel that could not be asked what it is hosting (§18).
 *
 * **It exists so that an adapter never has to return `[]` to mean "I do not
 * know".** An empty answer means this panel hosts no sites, and
 * `DiscoverSites` retires every node the adapter has ever written on the
 * strength of it — so a panel that was down for four minutes would empty a
 * customer's fleet, clear the alert about the vulnerable plugin, and then
 * quietly put it all back the next morning.
 *
 * Every reason named separately, the rule `PackageRefused` set: a mirror that
 * timed out, a token that was revoked and a toolkit that is not installed are
 * three different things for an operator to do something about.
 */
final class SiteInventoryUnavailable extends RuntimeException
{
    public static function noAnswer(string $source): self
    {
        return new self('The panel '.$source.' did not answer.');
    }

    public static function refused(string $source): self
    {
        return new self('The panel '.$source.' refused the credential.');
    }

    public static function answered(string $source, int $status): self
    {
        return new self('The panel '.$source.' answered '.$status.'.');
    }

    /**
     * The toolkit is not there at all, which is not the same as an outage.
     *
     * A panel without WP Toolkit installed answers 404 for ever, and an
     * operator reading "did not answer" would go and look at the network.
     */
    public static function notInstalled(string $source): self
    {
        return new self('The panel '.$source.' has no site toolkit installed.');
    }

    public static function unreadable(string $source, string $what): self
    {
        return new self('The panel '.$source.' answered something unreadable: '.$what.'.');
    }
}
