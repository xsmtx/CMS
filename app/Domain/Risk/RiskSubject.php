<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Everything an evaluator is allowed to see about an order.
 *
 * A flat value object rather than the Eloquent model, for two reasons. An
 * evaluator shipped by a module should not be handed the whole database
 * through a relation, and the signals a decision was made on are then
 * exactly the signals that can be recorded alongside it.
 */
final readonly class RiskSubject
{
    /**
     * @param  int  $priorOrders  orders this customer has placed before
     * @param  int  $recentOrders  orders from this customer or address in the velocity window
     * @param  int  $failedPayments  failed payment attempts on this customer's history
     */
    public function __construct(
        public Money $total,
        public ?CarbonImmutable $customerCreatedAt = null,
        public int $priorOrders = 0,
        public int $recentOrders = 0,
        public int $failedPayments = 0,
        public ?string $billingCountry = null,
        public ?string $ipCountry = null,
        public ?string $ipAddress = null,
        public ?string $email = null,
        public bool $emailVerified = false,
    ) {}

    /**
     * How long the customer has existed, in whole days. Null when there is
     * no customer yet, which is itself a signal.
     */
    public function accountAgeInDays(?CarbonImmutable $now = null): ?int
    {
        if ($this->customerCreatedAt === null) {
            return null;
        }

        return (int) $this->customerCreatedAt->diffInDays($now ?? CarbonImmutable::now());
    }

    public function isFirstOrder(): bool
    {
        return $this->priorOrders === 0;
    }

    /**
     * Whether the request came from a country other than the billing one.
     *
     * Null when either side is unknown: "we could not tell" is not the same
     * as "they match", and treating it as a match would silently disable
     * the rule.
     */
    public function countryMismatch(): ?bool
    {
        if ($this->billingCountry === null || $this->ipCountry === null) {
            return null;
        }

        return strtoupper($this->billingCountry) !== strtoupper($this->ipCountry);
    }
}
