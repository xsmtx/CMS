<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Dns\DnsZone;

/**
 * Something that can say what a zone contains (§8).
 *
 * PowerDNS, BIND, Knot, Cloudflare, Route 53 — and the point of the contract
 * is that core needs none of them to know what a *good* zone looks like.
 * "Does this domain have exactly one SPF record, and does it end in `-all`"
 * is a question with one right answer and no vendor in it; fetching the
 * records is the only part that differs, so that is the only part that is an
 * adapter.
 *
 * `integration-modules-plan.md` lists DNS as blocked on this contract. It is
 * not blocked any more.
 *
 * **Read only, for now and on purpose.** `Capability::DnsRecordWrite` exists
 * and has no method here, the third time this pattern appears: a bulk record
 * change is how a business disappears from the internet for four hours, and
 * it belongs behind §6's guarded workflow rather than behind a method
 * anything could call. The contract that reads is useful on its own and
 * ships first.
 */
interface DnsProvider extends InfrastructureAdapter
{
    /**
     * The zones this source is authoritative for.
     *
     * Names only, because a provider holding four thousand zones should not
     * be made to send every record in all of them to answer "which do you
     * have". Core asks for the ones it recognises.
     *
     * @return list<string>
     */
    public function zones(): array;

    /**
     * One zone's records, or null when this source does not hold it.
     *
     * Null rather than an exception: "I am not authoritative for that" is an
     * ordinary answer when an installation has three DNS providers, and a
     * sweep that treated it as a failure would report two failures for every
     * zone it successfully read.
     */
    public function zone(string $name): ?DnsZone;
}
