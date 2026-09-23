<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * How a domain came to be held here.
 *
 * Recorded rather than derived, because it cannot be derived later: the
 * order line that said so may be gone, and a transfer looks exactly like a
 * registration once it has completed. "Did we register this or take it
 * over" is a question an operator asks when a customer disputes who owns
 * a name.
 */
enum DomainOrderType: string
{
    case Register = 'register';
    case Transfer = 'transfer';

    /** Created by the renewal sweep rather than by a customer's order. */
    case Renewal = 'renewal';

    public function labelKey(): string
    {
        return 'domains.order_types.'.$this->value;
    }
}
