<?php

declare(strict_types=1);

namespace App\Domain\Provisioning\Events;

final readonly class ServiceSuspended
{
    public function __construct(
        public string $serviceId,
        public string $organizationId,
        public ?string $reason = null,
        public ?string $correlationId = null,
    ) {}
}
