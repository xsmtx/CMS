<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Identity\AccountStatus;

final readonly class ContactAttributes
{
    /**
     * @param  array<string, mixed>  $customFields
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public bool $portalAccess,
        public bool $isPrimary,
        public AccountStatus $status,
        public bool $notifyInvoices = true,
        public bool $notifySupport = true,
        public bool $notifyProduct = true,
        public bool $notifyMarketing = false,
        public array $customFields = [],
    ) {}
}
