<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * When a product is set up.
 *
 * `OnPayment` is the default and the only one most installations should
 * use. `OnOrder` sets up before the money arrives, which is a decision with
 * a cost an operator should have to choose deliberately.
 */
enum AutoSetup: string
{
    /** An operator runs it by hand. */
    case None = 'none';

    /** As soon as the order is placed, paid or not. */
    case OnOrder = 'on_order';

    /** Once the order is paid. */
    case OnPayment = 'on_payment';

    public function labelKey(): string
    {
        return 'provisioning.auto_setup.'.$this->value;
    }
}
