<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * One BGP neighbour, and whether it is speaking.
 *
 * `prefixesReceived` is the number that actually tells an operator something.
 * A session can be `established` and carrying nothing, which looks healthy on
 * every status page and means the transit is down - so the count is part of
 * the answer rather than a detail behind it.
 */
final readonly class BgpSession
{
    public function __construct(
        public string $peer,
        public BgpState $state = BgpState::Unknown,
        public ?int $remoteAsn = null,
        public ?int $localAsn = null,
        public ?int $prefixesReceived = null,
        public ?int $prefixesAdvertised = null,
        /** How long it has held this state, in seconds. */
        public ?int $uptimeSeconds = null,
        public ?string $description = null,
    ) {}
}
