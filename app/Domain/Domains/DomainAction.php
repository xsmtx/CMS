<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * What a domain price is for.
 *
 * Four actions rather than one price, because they genuinely differ: a
 * `.com` transfer usually costs a year's renewal, a redemption costs many
 * times a registration, and an operator who could only enter one number
 * would have to choose which of those to be wrong about.
 */
enum DomainAction: string
{
    case Register = 'register';
    case Renew = 'renew';
    case Transfer = 'transfer';

    /** Recovering a name from the registry's redemption period. */
    case Redeem = 'redeem';

    public function labelKey(): string
    {
        return 'domains.actions.'.$this->value;
    }

    /**
     * Whether a customer can ask for this themselves.
     *
     * Redemption is an operator's action: it costs a multiple of a
     * registration and usually involves a conversation.
     */
    public function isSelfService(): bool
    {
        return $this !== self::Redeem;
    }
}
