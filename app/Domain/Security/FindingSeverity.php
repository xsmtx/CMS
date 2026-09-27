<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * How loudly to say a zone finding (§8).
 *
 * **Two, and deliberately not `AlertSeverity`.** That enum's three members are
 * distinguished by *when somebody is interrupted* — during the day, now,
 * customers are affected — and a zone finding never interrupts anybody: it
 * sits on a list until an operator reads it. Borrowing it would have been the
 * mistake Phase D found in the other direction, where a severity scale wore
 * another scale's words and two adjacent columns said the same thing about
 * different things.
 *
 * The line between them is whether the zone is **objectively broken**. Two
 * SPF records and `+all` are RFC violations; a missing DMARC record is a
 * policy a business has not chosen yet. A list where both look equally urgent
 * is a list an operator stops reading, and then misses the `+all`.
 */
enum FindingSeverity: string
{
    /** An RFC violation, or a configuration that is actively harmful. */
    case Warning = 'warning';

    /** True, worth knowing, and not necessarily wrong. */
    case Information = 'info';

    /**
     * The tone `AppStatus` draws it in, decided here rather than in a Vue
     * file — one mapping, on the server.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Warning => 'warning',
            self::Information => 'info',
        };
    }

    public function labelKey(): string
    {
        return 'security.dns.severities.'.$this->value;
    }
}
