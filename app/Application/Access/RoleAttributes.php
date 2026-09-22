<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\RoleScope;

final readonly class RoleAttributes
{
    /**
     * @param  list<string>  $permissionSlugs
     */
    public function __construct(
        public string $name,
        public string $slug,
        public RoleScope $scope,
        public ?string $description = null,
        public array $permissionSlugs = [],
    ) {}
}
