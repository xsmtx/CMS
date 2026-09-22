<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Ordering\Models\Order;
use App\Support\Audit\Contracts\AuditLabel;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order from one state to another.
 *
 * The only way an order's status changes. Everything goes through here so
 * that the state machine is actually enforced, the history row is always
 * written, and the audit trail never has a gap where someone updated the
 * column directly.
 */
final readonly class TransitionOrder
{
    public function handle(
        Order $order,
        OrderStatus $target,
        ?Model $actor = null,
        ?string $reason = null,
    ): Order {
        $from = $order->status;

        if ($from === $target) {
            return $order;
        }

        if (! $from->canTransitionTo($target)) {
            throw InvalidOrderTransition::between($from, $target);
        }

        DB::transaction(function () use ($order, $from, $target, $actor, $reason): void {
            $order->update(['status' => $target->value]);

            $order->statusHistory()->create([
                'organization_id' => $order->organization_id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'actor_type' => $actor === null ? null : $actor::class,
                'actor_id' => $actor?->getKey(),
                'actor_label' => $actor instanceof AuditLabel ? $actor->auditLabel() : null,
                'reason' => $reason,
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });

        Audit::action('ordering.order.status_changed')
            ->by($actor)
            ->on($order)
            ->forOrganization($order->organization_id)
            ->changed(['status' => $from->value], ['status' => $target->value])
            ->because($reason)
            ->write();

        return $order;
    }

    /**
     * Record the first state of a new order without pretending it was a
     * transition from somewhere.
     */
    public function record(Order $order, ?Model $actor = null, ?string $reason = null): void
    {
        $order->statusHistory()->create([
            'organization_id' => $order->organization_id,
            'from_status' => null,
            'to_status' => $order->status->value,
            'actor_type' => $actor === null ? null : $actor::class,
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor instanceof AuditLabel ? $actor->auditLabel() : null,
            'reason' => $reason,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
