<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * The questions core asks of a zone (§8).
 *
 * **Core owns these because they have one right answer and no vendor in
 * them.** "Does this domain have exactly one SPF record" is RFC 7208 §3.2 and
 * not an opinion; fetching the records is the only part that differs between
 * providers, so that is the only part that is an adapter.
 *
 * **What is not here is as deliberate as what is.** Core does not judge
 * whether a domain *should* have mail, does not grade a DMARC policy, and
 * does not tell anybody what their TTLs ought to be. Those are a business's
 * own decisions, and a platform that reported them as faults would be a
 * platform whose findings list gets ignored — which takes the real findings
 * with it.
 */
enum ZoneCheck: string
{
    /** Two SPF records is an RFC violation: a resolver gives up on both. */
    case SpfDuplicate = 'spf_duplicate';

    /** `+all` says "anyone may send as us", which is worse than no SPF. */
    case SpfPermissive = 'spf_permissive';

    /** No SPF at all. A fact rather than a fault — plenty of domains never send. */
    case SpfMissing = 'spf_missing';

    /** No DMARC record. Also a fact: it is a policy a business chooses. */
    case DmarcMissing = 'dmarc_missing';

    /** DMARC present but in monitoring mode, which many deploy deliberately. */
    case DmarcMonitorOnly = 'dmarc_monitor_only';

    /** No MX at the apex. A fact: a domain that never receives mail needs none. */
    case MxMissing = 'mx_missing';

    /**
     * Fewer than two nameservers.
     *
     * RFC 1034 §4.1 wants at least two, and a single one is the difference
     * between a maintenance window and an outage.
     */
    case NsTooFew = 'ns_too_few';

    /** The zone is not signed, where the provider could say. */
    case DnssecOff = 'dnssec_off';

    /**
     * How loudly to say it.
     *
     * Only the two that are objectively broken are warnings. Everything else
     * is information, because a findings list full of things that are fine is
     * a list an operator stops reading — and then misses the `+all`.
     */
    public function severity(): FindingSeverity
    {
        return match ($this) {
            self::SpfDuplicate, self::SpfPermissive, self::NsTooFew => FindingSeverity::Warning,
            default => FindingSeverity::Information,
        };
    }

    public function labelKey(): string
    {
        return 'security.dns.checks.'.$this->value;
    }

    public function detailKey(): string
    {
        return 'security.dns.details.'.$this->value;
    }
}
