<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * A row a module adds to the admin menu.
 *
 * Declarative, and it lands in the Extensions section rather than wherever
 * the module would like. A module that could put a row next to Billing
 * could put a row that looks like Billing, and an operator has no way to
 * tell which rows are the platform's.
 *
 * The permission is the module's own. Hiding a row is presentation; the
 * route behind it is authorized separately, as every route in this
 * platform is.
 */
final readonly class NavigationItem
{
    public function __construct(
        public string $label,
        public string $path,
        public ?string $permission = null,
    ) {}
}
