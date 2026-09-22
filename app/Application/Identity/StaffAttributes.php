<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\AccountStatus;

/**
 * The editable shape of a staff account.
 *
 * A DTO rather than a request object so the use cases can be driven from the
 * console and from a module without an HTTP request in sight.
 */
final readonly class StaffAttributes
{
    /**
     * @param  list<string>  $roleIds
     */
    public function __construct(
        public string $name,
        public string $email,
        public AccountStatus $status,
        public array $roleIds = [],
    ) {}
}
