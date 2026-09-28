<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Mail\BlocklistListing;

/**
 * Something that knows whether an address is on a blocklist (§13).
 *
 * A reputation service, a DNSBL resolver, a mail vendor's own dashboard.
 *
 * **Core asks about its own addresses.** A provider will happily answer about
 * any address on the internet; walking anything wider would be this platform
 * checking up on other people's networks, which is neither its business nor
 * its bill.
 *
 * **It answers the whole current state for the addresses it was asked
 * about.** A listing that is no longer returned has been lifted, and core
 * clears its row rather than deleting it — "we were listed for nine days in
 * March" is exactly the history somebody needs when a customer asks why their
 * mail was slow.
 *
 * Nothing here delists. Asking a blocklist to lift a listing is a form with a
 * human on the other end, usually a captcha, and sometimes a promise about
 * what has been fixed; a method for it would be a method that lies.
 */
interface ReputationProvider extends InfrastructureAdapter
{
    /**
     * Which of these addresses are listed, and where.
     *
     * @param  list<string>  $addresses
     * @return list<BlocklistListing>
     */
    public function listings(array $addresses): array;
}
