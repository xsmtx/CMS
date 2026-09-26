<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * Who a pool's addresses are for.
 *
 * The distinction §5 asks for, and it earns its place on the screen rather than
 * in the code: an operator looking for something to give a customer must not be
 * offered the management network, and somebody numbering a new switch must not
 * be offered the customer range. Nothing here refuses an allocation - a pool is
 * a label an operator chose and this platform does not know better - but the
 * lists are filtered by it and the screen says which kind it is looking at.
 */
enum PoolPurpose: string
{
    case Infrastructure = 'infrastructure';
    case Customer = 'customer';

    public function labelKey(): string
    {
        return 'network.pool_purposes.'.$this->value;
    }
}
