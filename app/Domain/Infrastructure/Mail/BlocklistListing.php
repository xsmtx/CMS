<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Mail;

/**
 * An address a blocklist says it will not accept mail from (§13).
 *
 * **Not a metric**, which is why it is not a `MetricKind`. A listing is a
 * yes-or-no fact about one address on one list, with a reason somebody wrote
 * and a way to ask for it to be lifted; storing it as a number would lose
 * every part of it that an operator needs to act.
 *
 * `delistUrl` is the one field that matters most in practice and the one a
 * platform is most likely to leave out. An operator who has found the listing
 * still has to find the form, and a list that names a problem without naming
 * the way out is a list they resent.
 *
 * `reason` is the blocklist's own words. Core does not translate it, shorten
 * it or interpret it: it is evidence in a conversation with somebody else,
 * and paraphrasing evidence is how a delisting request gets refused.
 */
final readonly class BlocklistListing
{
    public function __construct(
        public string $address,
        /** The list's own name, as it calls itself: `zen.spamhaus.org`. */
        public string $list,
        public ?string $reason = null,
        public ?string $delistUrl = null,
    ) {}
}
