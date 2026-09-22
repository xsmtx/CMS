<?php

declare(strict_types=1);

namespace App\Domain\Domains\Contracts;

use App\Domain\Domains\DomainQuote;

/**
 * Availability and price for a domain.
 *
 * The seam a registrar sits behind in Phase 7. Phase 3 ships a null
 * implementation that reports nothing available, so the cart, checkout and
 * order line shapes are fixed and tested before a registrar exists — which
 * is the point of defining the contract first rather than after.
 */
interface DomainPricing
{
    /**
     * Null when this implementation cannot price the domain at all, which
     * is different from pricing it as unavailable.
     */
    public function quote(string $domain, int $years, string $currencyCode): ?DomainQuote;

    /**
     * @return list<string> the TLDs this implementation can price
     */
    public function supportedTlds(): array;
}
