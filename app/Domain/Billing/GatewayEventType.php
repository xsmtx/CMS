<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * The handful of provider events core actually acts on.
 *
 * An adapter maps a provider's vocabulary onto this; core never learns
 * `payment_intent.succeeded` or any other provider's spelling.
 */
enum GatewayEventType: string
{
    case PaymentCompleted = 'payment.completed';
    case PaymentFailed = 'payment.failed';
    case PaymentRefunded = 'payment.refunded';

    /** Something we do not act on, recorded so the log is complete. */
    case Ignored = 'ignored';
}
