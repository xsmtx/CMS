<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Catalog\BillingCycle;

/**
 * What a customer chose on the configure screen.
 *
 * @phpstan-type OptionChoice array{option_id?: string|null, quantity?: int}
 */
final readonly class AddToCartRequest
{
    /**
     * @param  array<string, array{option_id?: string|null, quantity?: int}>  $options  keyed by option group id
     * @param  list<string>  $addonIds
     */
    public function __construct(
        public string $productId,
        public BillingCycle $cycle,
        public int $quantity = 1,
        public array $options = [],
        public array $addonIds = [],
        public ?string $domain = null,
        public int $domainYears = 1,
    ) {}
}
