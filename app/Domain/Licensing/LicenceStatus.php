<?php

declare(strict_types=1);

namespace App\Domain\Licensing;

/**
 * What the vendor says about this licence.
 *
 * The server's word, carried in the token. The installation never decides a
 * status — it can only observe one, and observe that the token has expired.
 *
 * `Suspended` and `Revoked` are separate because they end differently: a
 * suspension is a conversation about an invoice and the licence comes back,
 * and a revocation does not. An installation that collapsed the two would
 * tell an operator their licence was gone when it was late.
 */
enum LicenceStatus: string
{
    case Active = 'active';

    /** Temporarily stopped. Usually money, and usually reversible. */
    case Suspended = 'suspended';

    /** Ended for good. */
    case Revoked = 'revoked';

    /** A time-limited licence that ran out. */
    case Expired = 'expired';

    public function labelKey(): string
    {
        return 'licensing.statuses.'.$this->value;
    }

    /**
     * Whether an operator has to do something about it.
     */
    public function needsAttention(): bool
    {
        return $this !== self::Active;
    }
}
