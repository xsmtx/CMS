<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

use Carbon\CarbonImmutable;

/**
 * One attack, as the thing that mitigated it describes it.
 *
 * **Not the flow series** (§7, §14). A NetFlow collector inside a billing
 * database is a time-series store nobody sized, for the second time in this
 * product — so what crosses here is the event: what was hit, when, how hard
 * at its worst, what shape it was, and what was done about it. Top talkers
 * are asked of an adapter when a screen wants them, and kept nowhere.
 *
 * `reference` is the provider's own identifier for the event and it is the
 * whole of the idempotency: the sweep runs every few minutes and an attack
 * lasting an hour is reported again each time, usually with a later `endedAt`
 * and a higher peak. Deduplicating on anything else — the address and a
 * timestamp, say — would either merge two genuine attacks or write the same
 * one forty times.
 *
 * `endedAt` being null means **still running**, which is the state an
 * operator opens this screen for. It is not "we do not know when it ended".
 *
 * The peaks are nullable because a vendor reports one, the other or both, and
 * a platform that invented the missing one would be a platform whose figures
 * could not be compared with the invoice for the transit that carried it.
 */
final readonly class DdosAttack
{
    /**
     * @param  string  $target  The address that was hit, as text. Attributed to
     *                          a customer through `ip_assignments`, which is
     *                          what that append-only table exists for.
     * @param  list<DdosVector>  $vectors  Usually one, sometimes several at
     *                                     once — a mixed attack is ordinary.
     */
    public function __construct(
        public string $reference,
        public string $target,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt = null,
        public ?float $peakGbps = null,
        public ?float $peakMpps = null,
        public array $vectors = [],
        public ?string $mitigation = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->endedAt === null;
    }
}
