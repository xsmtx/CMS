<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * The domain an adapter is being asked about.
 *
 * Identity and the few registry-facing details, not the record: an adapter
 * has no business knowing what the customer paid or who else they are.
 */
final readonly class DomainReference
{
    public function __construct(
        public string $id,
        public DomainName $name,
        public ?string $externalId = null,
    ) {}
}
