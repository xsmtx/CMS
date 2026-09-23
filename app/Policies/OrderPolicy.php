<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class OrderPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('orders.view');
    }

    public function view(StaffUser $actor, Order $order): bool
    {
        return $actor->hasPermissionTo('orders.view')
            && $this->withinBoundary($actor, $order);
    }

    /**
     * Taking an order over the phone. No particular order is in hand yet,
     * so there is no boundary to check — the customer the operator picks
     * is resolved through one.
     */
    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('orders.manage');
    }

    public function update(StaffUser $actor, Order $order): bool
    {
        return $actor->hasPermissionTo('orders.manage')
            && $this->withinBoundary($actor, $order);
    }

    /**
     * Whether this person reviews holds at all, asked before any particular
     * order is in hand — the queue screen needs it to decide whether to
     * offer the buttons.
     */
    public function reviewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('orders.review');
    }

    /**
     * Clearing a risk hold overrides the platform's own judgement about an
     * order, so it is its own capability rather than part of managing one.
     */
    public function review(StaffUser $actor, Order $order): bool
    {
        return $actor->hasPermissionTo('orders.review')
            && $this->withinBoundary($actor, $order);
    }
}
