<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * The things that can be asked of a registrar.
 *
 * Named here rather than as strings at call sites, because every one of
 * them is recorded in the event log and an operator reading that log a year
 * later should not be decoding typos.
 */
enum DomainOperation: string
{
    case CheckAvailability = 'check_availability';
    case Register = 'register';
    case Transfer = 'transfer';
    case Renew = 'renew';
    case SetNameservers = 'set_nameservers';
    case SetLock = 'set_lock';
    case SetAutoRenew = 'set_auto_renew';
    case RequestTransferCode = 'request_transfer_code';
    case Sync = 'sync';

    public function labelKey(): string
    {
        return 'domains.operations.'.$this->value;
    }
}
