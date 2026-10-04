<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

/**
 * What kind of thing we buy from somebody (§24).
 *
 * The handoff's own list, and a closed one on purpose: these are the eight
 * things a hosting business buys, and an operator filtering their vendors
 * wants the same words every time. `Other` is the honest escape rather than
 * a free string, because a vendor nobody could categorise is still a vendor
 * somebody has to pay.
 *
 * It decides nothing — no behaviour hangs off a kind. It exists so that
 * "which transit contracts end this quarter" is a `where` rather than a
 * question somebody answers by reading forty rows.
 */
enum VendorKind: string
{
    case Datacenter = 'datacenter';
    case Transit = 'transit';
    case Hardware = 'hardware';
    case Software = 'software';
    case Registrar = 'registrar';
    case Backup = 'backup';
    case Cloud = 'cloud';
    case Ddos = 'ddos';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'vendors.kinds.'.$this->value;
    }
}
