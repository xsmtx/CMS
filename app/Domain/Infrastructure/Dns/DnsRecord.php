<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Dns;

/**
 * One record in a zone, as an adapter read it.
 *
 * `name` is whatever the provider calls it — some write the fully qualified
 * name and some write the label relative to the zone. Core normalises when it
 * asks a question rather than at the boundary, because an adapter that had to
 * guess which convention core wanted would guess wrong for half of them.
 *
 * `value` is the record's data as a single string, which is what every
 * provider API actually returns and what every zone file line contains. An
 * MX's priority is part of it; parsing that out is the checks' job, once, and
 * not something twenty adapters should each attempt.
 */
final readonly class DnsRecord
{
    public function __construct(
        public string $name,
        public DnsRecordType $type,
        public string $value,
        public ?int $ttl = null,
    ) {}

    /** The name relative to a zone, with the trailing dot gone. */
    public function relativeTo(string $zone): string
    {
        $name = mb_strtolower(rtrim(trim($this->name), '.'));
        $suffix = mb_strtolower(rtrim(trim($zone), '.'));

        if (in_array($name, [$suffix, '', '@'], true)) {
            return '@';
        }

        return str_ends_with($name, '.'.$suffix)
            ? substr($name, 0, -strlen($suffix) - 1)
            : $name;
    }

    /** Whether this record sits at the zone apex. */
    public function isApex(string $zone): bool
    {
        return $this->relativeTo($zone) === '@';
    }
}
