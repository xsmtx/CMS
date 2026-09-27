<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Infrastructure\Dns\DnsRecordType;
use App\Domain\Infrastructure\Dns\DnsZone;
use App\Domain\Security\ZoneCheck;

/**
 * Asks a zone the questions core knows the answers to (§8).
 *
 * **Every check here is an RFC, not an opinion.** Two SPF records is a
 * violation that makes resolvers give up on both; `+all` says anyone may send
 * as you; one nameserver is the difference between a maintenance window and
 * an outage. Core does not grade a DMARC policy, does not decide whether a
 * domain ought to have mail, and does not have views about TTLs — a findings
 * list full of things that are fine is a list an operator stops reading, and
 * then misses the `+all`.
 *
 * **It is pure.** Given a zone it returns findings, touches no database and
 * knows no adapter. That is what makes it testable against a zone somebody
 * typed out in a test, which is the only way these rules can be trusted
 * before any real provider has ever answered.
 */
final readonly class InspectZone
{
    /**
     * @return list<ZoneCheck>
     */
    public function handle(DnsZone $zone): array
    {
        return array_values(array_filter([
            ...$this->spf($zone),
            ...$this->dmarc($zone),
            ...$this->mail($zone),
            ...$this->delegation($zone),
            ...$this->signing($zone),
        ]));
    }

    /**
     * @return list<ZoneCheck>
     */
    private function spf(DnsZone $zone): array
    {
        $records = array_values(array_filter(
            $zone->textAt('@'),
            static fn (string $value): bool => str_starts_with(mb_strtolower(trim($value)), 'v=spf1'),
        ));

        if ($records === []) {
            return [ZoneCheck::SpfMissing];
        }

        $findings = [];

        // RFC 7208 §3.2: more than one is a permanent error, and a resolver
        // that finds two treats the domain as having none.
        if (count($records) > 1) {
            $findings[] = ZoneCheck::SpfDuplicate;
        }

        foreach ($records as $record) {
            $normalised = mb_strtolower(trim($record));

            // `+all` and a bare `all` both mean pass-everything. The bare
            // form is the one people write by accident, because the `+` is
            // the default qualifier and reads like it means nothing.
            if (preg_match('/(?:^|\s)\+?all\b/', $normalised) === 1) {
                $findings[] = ZoneCheck::SpfPermissive;

                break;
            }
        }

        return $findings;
    }

    /**
     * @return list<ZoneCheck>
     */
    private function dmarc(DnsZone $zone): array
    {
        $records = array_values(array_filter(
            $zone->textAt('_dmarc'),
            static fn (string $value): bool => str_starts_with(mb_strtolower(trim($value)), 'v=dmarc1'),
        ));

        if ($records === []) {
            return [ZoneCheck::DmarcMissing];
        }

        foreach ($records as $record) {
            // `p=none` is monitoring, which most people deploy on purpose for
            // months before tightening. Information, never a warning.
            if (preg_match('/\bp\s*=\s*none\b/i', $record) === 1) {
                return [ZoneCheck::DmarcMonitorOnly];
            }
        }

        return [];
    }

    /**
     * @return list<ZoneCheck>
     */
    private function mail(DnsZone $zone): array
    {
        return $zone->ofType(DnsRecordType::Mx, apexOnly: true) === []
            ? [ZoneCheck::MxMissing]
            : [];
    }

    /**
     * @return list<ZoneCheck>
     */
    private function delegation(DnsZone $zone): array
    {
        $nameservers = [];

        foreach ($zone->ofType(DnsRecordType::Ns, apexOnly: true) as $record) {
            // Deduplicated: a provider that lists the same nameserver twice
            // has not given the zone two of them.
            $nameservers[mb_strtolower(rtrim(trim($record->value), '.'))] = true;
        }

        return count($nameservers) < 2 ? [ZoneCheck::NsTooFew] : [];
    }

    /**
     * @return list<ZoneCheck>
     */
    private function signing(DnsZone $zone): array
    {
        // Null means the provider could not say, which is not the same as
        // "off" — reporting an unsigned zone there would be telling an
        // operator something this platform does not know.
        return $zone->dnssec === false ? [ZoneCheck::DnssecOff] : [];
    }
}
