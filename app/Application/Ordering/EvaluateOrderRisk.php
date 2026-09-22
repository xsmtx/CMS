<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Crm\AddressType;
use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Risk\RiskAssessment;
use App\Domain\Risk\RiskSubject;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Gathers the signals an order can be judged on, and asks the evaluator.
 *
 * The gathering is here rather than in the evaluator so that an evaluator
 * shipped by a module is handed a flat set of facts instead of the whole
 * database through a relation — and so the facts a decision was made on are
 * exactly the facts that can be recorded beside it.
 */
final readonly class EvaluateOrderRisk
{
    public function __construct(private RiskEvaluator $evaluator) {}

    public function handle(Order $order, ?string $ipCountry = null): RiskAssessment
    {
        $customer = $order->customer;

        return $this->evaluator->evaluate(new RiskSubject(
            total: $order->total,
            customerCreatedAt: $customer?->created_at,
            priorOrders: $this->priorOrders($order),
            recentOrders: $this->recentOrders($order),
            failedPayments: 0,
            billingCountry: $this->billingCountry($customer),
            ipCountry: $ipCountry,
            ipAddress: $order->ip_address,
            email: $order->contact?->email,
            emailVerified: $order->contact instanceof Contact && $order->contact->email_verified_at !== null,
        ));
    }

    private function priorOrders(Order $order): int
    {
        return Order::query()
            ->where('customer_id', $order->customer_id)
            ->whereKeyNot($order->getKey())
            ->whereNotNull('placed_at')
            ->count();
    }

    /**
     * Orders from this customer in the velocity window. A burst from one
     * account is the cheapest signal there is.
     */
    private function recentOrders(Order $order): int
    {
        $hours = (int) config('platform.risk.rules.velocity_hours', 24);

        return Order::query()
            ->where('customer_id', $order->customer_id)
            ->whereKeyNot($order->getKey())
            ->where('created_at', '>=', CarbonImmutable::now()->subHours(max($hours, 1)))
            ->count();
    }

    private function billingCountry(?Customer $customer): ?string
    {
        // The billing address, because that is the one a card is checked
        // against. A customer with none is not a mismatch; it is unknown,
        // which the subject reports separately.
        return $customer?->addressFor(AddressType::Billing)?->country_code;
    }
}
