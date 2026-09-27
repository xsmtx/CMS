<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Dns;

/**
 * The record types core knows how to reason about (§8).
 *
 * A closed list, and deliberately short: these are the ones the zone checks
 * ask questions of. An adapter that holds NAPTR, SSHFP or a vendor's own
 * pseudo-record is not wrong — core simply has nothing to say about them, and
 * a member for every type in the IANA registry would be a vocabulary nobody
 * reads pretending to be knowledge.
 *
 * `Other` exists so an adapter can hand over what it has without lying about
 * the type. A record core cannot classify is still a record somebody may need
 * to see on the screen.
 */
enum DnsRecordType: string
{
    case A = 'a';
    case Aaaa = 'aaaa';
    case Cname = 'cname';
    case Mx = 'mx';
    case Ns = 'ns';
    case Txt = 'txt';
    case Srv = 'srv';
    case Caa = 'caa';
    case Soa = 'soa';
    case Other = 'other';

    /**
     * From whatever an adapter calls it.
     *
     * Case-insensitive and unknown-tolerant: a provider writing `TXT`, `txt`
     * or `Txt` means the same thing, and one writing `HTTPS` means something
     * core has no opinion about rather than something that should throw.
     */
    public static function fromName(string $name): self
    {
        return self::tryFrom(mb_strtolower(trim($name))) ?? self::Other;
    }

    public function labelKey(): string
    {
        return 'security.dns.types.'.$this->value;
    }
}
