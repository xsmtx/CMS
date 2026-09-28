<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * A way money quietly stops arriving (§21).
 *
 * Four questions, and every one of them is a join over rows this platform
 * already owns — no adapter, no provider, no model. That is what makes this
 * family different from everything else in Phase H: reconciliation can only
 * be as true as an untested parse, and these four are arithmetic.
 *
 * **None of them is an accusation.** A service given away to a charity, a
 * domain somebody is holding for a customer arriving in March, a payment
 * taken on account before the invoice was raised — each is a finding and
 * each is somebody's deliberate decision. The screen says how much is at
 * stake and an operator says whether it matters, which is why a dismissal
 * exists for these exactly as it does for a reconciliation finding.
 *
 * **`UnmatchedPayment` is the one that points the other way.** The first
 * three are money not asked for; this one is money already taken and not
 * attached to anything, which is a customer who has paid and may still be
 * chased for it.
 */
enum LeakageKind: string
{
    /** Active, priced, and nothing has invoiced it since it fell due. */
    case ServiceNotBilled = 'service_not_billed';

    /** Past its renewal date with no renewal invoice raised. */
    case DomainNotRenewed = 'domain_not_renewed';

    /**
     * On a service that was invoiced, and absent from that invoice.
     *
     * The one a person would never find by reading invoices: the parent
     * line is there and looks right, and the extra backup space beside it
     * simply is not.
     */
    case AddonNotBilled = 'addon_not_billed';

    /** Money received and attached to no invoice. */
    case UnmatchedPayment = 'unmatched_payment';

    public function labelKey(): string
    {
        return 'intelligence.leakage.kinds.'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'intelligence.leakage.descriptions.'.$this->value;
    }

    /**
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     *
     * All four are `warning` and none is `critical`, which is deliberate:
     * nothing here is broken. Money is not arriving, and an operator decides
     * in the morning whether that was the intention — toning it as an
     * emergency would be the platform having an opinion about somebody's
     * commercial arrangements.
     */
    public function tone(): string
    {
        return 'warning';
    }
}
