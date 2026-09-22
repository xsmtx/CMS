<?php

declare(strict_types=1);

namespace App\Domain\Crm;

enum AddressType: string
{
    case Billing = 'billing';
    case Technical = 'technical';
    case Legal = 'legal';

    public function labelKey(): string
    {
        return 'crm.address_types.'.$this->value;
    }

    /**
     * An invoice snapshots this address at issue time, so changing it later
     * never rewrites a document that has already been sent.
     */
    public function appearsOnInvoices(): bool
    {
        return $this === self::Billing || $this === self::Legal;
    }
}
