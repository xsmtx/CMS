<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\DomainName;
use App\Domain\Shared\Money;

/**
 * One row of a search result: a name, what it would cost, and whether
 * anybody has confirmed it is free.
 *
 * `notSold` and "taken" are kept apart. One means the operator does not
 * offer this extension on this term; the other means somebody else owns the
 * name. Collapsing them would produce a search result that says "not
 * available" to a customer whose only problem is that they asked for three
 * years.
 */
final readonly class DomainOffer
{
    public function __construct(
        public DomainName $name,
        public int $years,
        public ?Money $price,
        public ?AvailabilityResult $availability = null,
        public ?Money $transferPrice = null,
        public bool $sold = true,
    ) {}

    public static function notSold(DomainName $name, int $years): self
    {
        return new self($name, $years, null, null, null, false);
    }

    public function isOrderable(): bool
    {
        return $this->sold
            && $this->price !== null
            && $this->availability?->isAvailable() === true;
    }

    /**
     * Whether a transfer is worth offering: the name is taken, somebody
     * confirmed that, and the operator sells transfers for this extension.
     */
    public function isTransferable(): bool
    {
        return $this->sold
            && $this->transferPrice !== null
            && $this->availability?->known === true
            && $this->availability->available === false;
    }

    public function isUnknown(): bool
    {
        return $this->sold && $this->availability?->known === false;
    }
}
