<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\CustomerStatus;

final readonly class CustomerAttributes
{
    /**
     * @param  list<string>  $tagIds
     * @param  array<string, mixed>  $customFields
     */
    public function __construct(
        public ?string $companyName,
        public ?string $legalName,
        public ?string $taxId,
        public ?string $taxIdType,
        public CustomerStatus $status,
        public string $currencyCode,
        public bool $marketingOptIn = false,
        public array $tagIds = [],
        public array $customFields = [],
    ) {}
}
