<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * How an attack was shaped, in the words mitigation vendors already use.
 *
 * A closed list rather than the vendor's own string, because the vector is
 * what an operator groups by — "we are getting hit with NTP reflection again"
 * is a sentence about a fortnight of events, and a column holding
 * `udp_ntp_reflection` from one appliance and `NTP Amplification` from the
 * next is a column nobody can group by.
 *
 * `Other` is the escape hatch and it is deliberately not a free string
 * alongside: an adapter that meets something this list has no word for
 * reports `Other` and the platform counts it, rather than growing a
 * vocabulary from whatever a vendor happened to print. A vector that turns
 * out to matter gets a member here, which is a decision.
 */
enum DdosVector: string
{
    /** Volume: fill the pipe until nothing else fits. */
    case UdpFlood = 'udp_flood';
    case IcmpFlood = 'icmp_flood';

    /** Reflection and amplification, by the service abused. */
    case DnsReflection = 'dns_reflection';
    case NtpReflection = 'ntp_reflection';
    case MemcachedReflection = 'memcached_reflection';
    case SsdpReflection = 'ssdp_reflection';

    /** State exhaustion: fill the table rather than the pipe. */
    case SynFlood = 'syn_flood';
    case AckFlood = 'ack_flood';

    /** Up the stack, where a firewall's rules stop helping. */
    case HttpFlood = 'http_flood';
    case TlsHandshake = 'tls_handshake';

    case Other = 'other';

    public function labelKey(): string
    {
        return 'network.ddos.vectors.'.$this->value;
    }
}
