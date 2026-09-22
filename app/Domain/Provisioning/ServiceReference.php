<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * The service an adapter is being asked about.
 *
 * Identity and the handful of remote details, not the record: an adapter
 * has no business knowing what the customer paid or who they are.
 *
 * `externalId` is what the provider calls this thing. It is the difference
 * between an operation that can be repeated safely and one that cannot, so
 * it is stored the moment a provider hands it over.
 */
final readonly class ServiceReference
{
    public function __construct(
        public string $id,
        public ?ServerConnection $server,
        public ?string $externalId,
        public ?string $username,
        public ?string $domain,
        public ?string $package,
    ) {}
}
