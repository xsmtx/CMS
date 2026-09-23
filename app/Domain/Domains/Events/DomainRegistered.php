<?php

declare(strict_types=1);

namespace App\Domain\Domains\Events;

final readonly class DomainRegistered
{
    public function __construct(
        public string $domainId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
