<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Everything needed to create one account.
 *
 * The values are the ones copied onto the service when it was created, not
 * a lookup through the catalog: a product repriced or renamed after the
 * fact must not change what gets set up.
 */
final readonly class ProvisioningRequest
{
    /**
     * @param  array<string, string>  $options  Configured options, as chosen.
     */
    public function __construct(
        public string $serviceId,
        public ?ServerConnection $server,
        public string $package,
        public ?string $domain,
        public ?string $username,
        public ?string $email,
        public array $options = [],
        public ?string $correlationId = null,
    ) {}
}
