<?php

declare(strict_types=1);

namespace App\Application\Catalog;

final readonly class CurrencyAttributes
{
    /**
     * @param  string  $rate  a decimal string, never a float
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $symbol = null,
        public string $rate = '1.00000000',
        public bool $isBase = false,
        public bool $isActive = true,
        public ?string $rateSource = null,
    ) {}
}
