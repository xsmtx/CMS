<?php

declare(strict_types=1);

namespace App\Application\Promotions;

use App\Domain\Promotions\PromotionApplication;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use Carbon\CarbonImmutable;

final readonly class PromotionAttributes
{
    /**
     * @param  list<string>|null  $billingCycles  null means every cycle
     * @param  list<string>  $productIds
     */
    public function __construct(
        public string $code,
        public string $name,
        public PromotionType $type,
        public PromotionScope $scope = PromotionScope::Order,
        public PromotionApplication $application = PromotionApplication::FirstPayment,
        public ?string $description = null,
        public ?int $amountMinor = null,
        public ?string $currencyCode = null,
        public ?string $percentage = null,
        public ?array $billingCycles = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?int $usageLimit = null,
        public ?int $perCustomerLimit = null,
        public ?int $minimumSubtotalMinor = null,
        public bool $newCustomersOnly = false,
        public bool $stackable = false,
        public bool $isActive = true,
        public array $productIds = [],
    ) {}
}
