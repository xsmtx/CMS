<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What a usage meter counts (§25).
 *
 * **A closed list, and the meter's unit is the *billing* unit.** An adapter
 * answers in the unit its meter declares or the reading is refused — the rule
 * `RecordSamples` already states about a unit from the wrong dimension,
 * applied where the consequence is money rather than a chart. Core converts
 * nothing: a platform that turned bytes into gigabytes to price them would be
 * a platform whose invoice disagrees with its own screen by a rounding error
 * nobody can find.
 *
 * So a seller who charges by the gigabyte configures a meter in gigabytes and
 * the adapter reports gigabytes. That is more work for the adapter and it is
 * the right place for it: the vendor knows what it measured.
 */
enum UsageUnit: string
{
    case Gigabytes = 'gigabytes';
    case Terabytes = 'terabytes';
    case Hours = 'hours';
    case Requests = 'requests';

    /** Mailboxes, accounts, seats — anything counted one at a time. */
    case Items = 'items';

    public function labelKey(): string
    {
        return 'billing.usage.units.'.$this->value;
    }
}
