<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Access\SystemRole;

/**
 * The person behind a new client.
 *
 * The four notification switches are the ones this platform actually has
 * (`NotificationCategory`), not a longer list copied from somewhere else:
 * a checkbox that sets nothing is a promise to a customer that nobody
 * keeps.
 */
final readonly class ClientContactAttributes
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone = null,
        public ?string $locale = null,
        public ?string $password = null,
        public SystemRole $role = SystemRole::AccountOwner,
        public bool $notifyInvoices = true,
        public bool $notifySupport = true,
        public bool $notifyProduct = true,
        public bool $notifyMarketing = false,
    ) {}
}
