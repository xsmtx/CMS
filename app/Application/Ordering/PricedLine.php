<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Domain\Shared\Money;

/**
 * One cart line with every amount worked out.
 *
 * The shape an order line is written from, which is why it carries the
 * names as well as the numbers: placing an order copies this, it does not
 * go back to the catalog.
 */
final readonly class PricedLine
{
    /**
     * @param  list<PricedOption>  $options
     */
    public function __construct(
        public string $itemId,
        public LineKind $kind,
        public string $name,
        public ?string $groupName,
        public ?BillingCycle $cycle,
        public int $quantity,
        public Money $unitRecurring,
        public Money $unitSetup,
        public Money $lineRecurring,
        public Money $lineSetup,
        public Money $discount,
        public Money $lineTotal,
        public array $options = [],
        public ?string $productId = null,
        public ?string $addonId = null,
        public ?string $parentItemId = null,
        public ?string $domain = null,
        public ?string $domainTld = null,
        public ?int $domainYears = null,
        public ?string $domainAction = null,
        /** @var list<string> */
        public array $domainAddons = [],
        /**
         * What an operator agreed instead of the catalogue's price, in
         * minor units. Null is not zero: no override means "whatever the
         * catalogue says", zero means somebody agreed to give it away.
         */
        public ?int $priceOverrideMinor = null,
    ) {}

    /**
     * Whether this line renews. A domain is paid for a term and renewed on
     * its own schedule, so it is not part of what the subscription costs
     * every cycle.
     */
    public function isRecurring(): bool
    {
        return $this->kind !== LineKind::Domain
            && $this->cycle !== null
            && $this->cycle->isRecurring();
    }

    public function withDiscount(Money $discount): self
    {
        return new self(
            $this->itemId,
            $this->kind,
            $this->name,
            $this->groupName,
            $this->cycle,
            $this->quantity,
            $this->unitRecurring,
            $this->unitSetup,
            $this->lineRecurring,
            $this->lineSetup,
            $discount,
            $this->lineRecurring->plus($this->lineSetup)->minus($discount),
            $this->options,
            $this->productId,
            $this->addonId,
            $this->parentItemId,
            $this->domain,
            $this->domainTld,
            $this->domainYears,
            $this->domainAction,
            $this->domainAddons,
            $this->priceOverrideMinor,
        );
    }
}
