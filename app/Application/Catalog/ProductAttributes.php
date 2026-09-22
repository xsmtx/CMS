<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\ProductType;

final readonly class ProductAttributes
{
    /**
     * @param  list<string>  $features
     * @param  int|null  $stock  null is unlimited, 0 is sold out
     */
    public function __construct(
        public string $productGroupId,
        public string $name,
        public ProductType $type,
        public ?string $slug = null,
        public ?string $tagline = null,
        public ?string $description = null,
        public array $features = [],
        public CatalogStatus $status = CatalogStatus::Active,
        public int $position = 0,
        public ?int $stock = null,
        public ?bool $requiresDomain = null,
    ) {}
}
