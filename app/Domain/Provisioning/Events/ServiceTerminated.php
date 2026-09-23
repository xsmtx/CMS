<?php

declare(strict_types=1);

namespace App\Domain\Provisioning\Events;

final readonly class ServiceTerminated
{
    public function __construct(
        public string $serviceId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
