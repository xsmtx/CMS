<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * What a cost is against (§21).
 *
 * Four scopes, and the difference between them is **which services share
 * it** — which is the only question an allocation has to answer.
 *
 * - `Server` is the ordinary one: a machine costs €200 a month and the
 *   accounts on it share it.
 * - `Product` is the cost of selling a thing rather than of running a
 *   machine — a backup add-on the provider buys per account, a control panel
 *   licence charged per plan.
 * - `Installation` is everything: the office, the monitoring bill, the
 *   accountant. Shared by every service there is.
 * - `Licence` is a cost that follows a *count* rather than a machine — and
 *   it is kept apart from `Product` because the count is usually of
 *   something the customer never sees. A cPanel licence on a server is a
 *   server cost; a cPanel licence billed per account is this.
 *
 * There is no `Customer` scope. A cost attributed to one customer is that
 * customer's price being wrong, which is a conversation rather than an
 * allocation — and a scope for it would let somebody quietly move a loss
 * onto one account.
 */
enum CostScope: string
{
    case Server = 'server';

    case Product = 'product';

    case Licence = 'licence';

    case Installation = 'installation';

    public function labelKey(): string
    {
        return 'intelligence.costs.scopes.'.$this->value;
    }

    /**
     * Whether an operator has to say which one.
     *
     * The installation is the installation; everything else names a row.
     */
    public function needsSubject(): bool
    {
        return $this !== self::Installation;
    }
}
