<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Ordering\TransitionOrder;
use App\Domain\Ordering\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ordering\OrderReviewRequest;
use App\Infrastructure\Ordering\Models\Order;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * Clearing or refusing an order the risk layer held.
 *
 * Its own controller because it answers to its own permission: overriding
 * the platform's judgement about an order is a different capability from
 * managing one, and every override is recorded with who made it and why.
 */
final class OrderReviewController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function release(OrderReviewRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $this->authorize('review', $order);

        $reason = $request->string('reason')->toString();

        $this->record($order, 'released', $reason);

        // An order with nothing to pay goes straight to paid; anything else
        // rejoins the queue it was pulled out of.
        $target = $order->total->isZero() ? OrderStatus::Paid : OrderStatus::AwaitingPayment;

        $transition->handle($order, $target, $this->actor->model(), $reason);

        return back()->with('status', __('ordering.orders.released'));
    }

    public function refuse(OrderReviewRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $this->authorize('review', $order);

        $reason = $request->string('reason')->toString();

        $this->record($order, 'refused', $reason);

        $transition->handle($order, OrderStatus::Cancelled, $this->actor->model(), $reason);

        return back()->with('status', __('ordering.orders.refused'));
    }

    private function record(Order $order, string $outcome, string $reason): void
    {
        $order->update([
            'risk_reviewed_at' => CarbonImmutable::now(),
            'risk_reviewed_by' => $this->actor->model()?->getAttribute('email'),
        ]);

        // A high-risk override: who, when, and in their own words.
        Audit::action('ordering.order.risk_'.$outcome)
            ->by($this->actor->model())
            ->on($order)
            ->forOrganization($order->organization_id)
            ->because($reason)
            ->withMetadata(['decision' => $order->risk_decision?->value])
            ->write();
    }
}
