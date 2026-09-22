<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\CatalogStatus;

final readonly class AddonAttributes
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public CatalogStatus $status = CatalogStatus::Active,
        public int $position = 0,
    ) {}
}
