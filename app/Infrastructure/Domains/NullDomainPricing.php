<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains;

use App\Domain\Domains\Contracts\DomainPricing;
use App\Domain\Domains\DomainQuote;

/**
 * Prices no domains.
 *
 * The default until a registrar is configured in Phase 7. It answers null
 * rather than quoting zero, because "we cannot price this" and "this is
 * free" are different answers and only one of them should let a domain
 * into a cart.
 */
final readonly class NullDomainPricing implements DomainPricing
{
    public function quote(string $domain, int $years, string $currencyCode): ?DomainQuote
    {
        return null;
    }

    public function supportedTlds(): array
    {
        return [];
    }
}
