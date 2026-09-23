<?php

declare(strict_types=1);

namespace App\Domain\Provisioning\Events;

/**
 * A service is set up and working.
 *
 * The event the welcome message has been waiting for since Phase 6, where
 * the provisioning flow ended with "WelcomeNotification" and nothing to
 * send it.
 */
final readonly class ServiceProvisioned
{
    public function __construct(
        public string $serviceId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
