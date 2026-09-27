<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Dns;

/**
 * A zone as an adapter found it (§8).
 *
 * `dnssec` is nullable and the null means **nobody looked**, not "off". A
 * provider API that does not expose DNSSEC state is a provider core cannot
 * answer the question for, and reporting `false` there would be the platform
 * telling an operator their zone is unsigned when it may not be — the same
 * decision `chain_ok` made about certificates.
 *
 * `serial` is the SOA serial where the adapter knows it. Comparing serials
 * across nameservers is what catches a zone that failed to transfer, and a
 * provider that can answer it is the only thing that can.
 */
final readonly class DnsZone
{
    /**
     * @param  list<DnsRecord>  $records
     */
    public function __construct(
        public string $name,
        public array $records,
        public ?int $serial = null,
        public ?bool $dnssec = null,
    ) {}

    /**
     * Every record of a type, optionally only at the apex.
     *
     * @return list<DnsRecord>
     */
    public function ofType(DnsRecordType $type, bool $apexOnly = false): array
    {
        return array_values(array_filter(
            $this->records,
            fn (DnsRecord $record): bool => $record->type === $type
                && (! $apexOnly || $record->isApex($this->name)),
        ));
    }

    /**
     * TXT records at a name, with the quoting a zone file adds removed.
     *
     * A provider hands back `"v=spf1 -all"` or `v=spf1 -all` depending on
     * whether it round-trips through a zone file, and a check that missed the
     * quoted form would report every zone on that provider as having no SPF.
     *
     * @return list<string>
     */
    public function textAt(string $name): array
    {
        $wanted = mb_strtolower(trim($name, '.')) ?: '@';

        $values = [];

        foreach ($this->ofType(DnsRecordType::Txt) as $record) {
            if ($record->relativeTo($this->name) !== $wanted) {
                continue;
            }

            // Long TXT values arrive split into quoted chunks, which is how
            // DKIM keys are carried. Joining them is the only way the value
            // reads as what it is.
            $value = preg_replace('/"\s*"/', '', trim($record->value));
            $values[] = trim((string) $value, '"');
        }

        return $values;
    }
}
