<?php

declare(strict_types=1);

namespace App\Domain\Domains;

use App\Domain\Shared\Money;

/**
 * What a domain would cost, for a term, right now.
 *
 * A quote rather than a price: registry pricing changes, and the number a
 * customer is shown has to be the number the order records, not one looked
 * up again later.
 */
final readonly class DomainQuote
{
    public function __construct(
        public string $domain,
        public string $tld,
        public int $years,
        public Money $registration,
        public ?Money $renewal = null,
        public bool $available = true,
        public bool $premium = false,
    ) {}

    public function isFree(): bool
    {
        return $this->registration->isZero();
    }
}
