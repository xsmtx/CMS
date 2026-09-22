<?php

declare(strict_types=1);

namespace App\Application\Catalog;

final readonly class OptionAttributes
{
    /**
     * @param  string|null  $id  the existing option being edited, if any
     * @param  list<PriceMatrixEntry>  $prices  signed deltas on the product price
     */
    public function __construct(
        public string $label,
        public string $value,
        public bool $isDefault = false,
        public int $position = 0,
        public ?string $id = null,
        public array $prices = [],
    ) {}
}
