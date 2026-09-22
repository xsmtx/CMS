<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\OptionType;

final readonly class OptionGroupAttributes
{
    /**
     * @param  list<OptionAttributes>  $options
     */
    public function __construct(
        public string $name,
        public string $key,
        public OptionType $type,
        public ?string $description = null,
        public bool $isRequired = false,
        public int $minQuantity = 0,
        public ?int $maxQuantity = null,
        public int $position = 0,
        public array $options = [],
    ) {}
}
