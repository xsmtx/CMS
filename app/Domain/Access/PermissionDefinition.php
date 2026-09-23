<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * A capability the application can check for, declared in code.
 *
 * Definitions are the source of truth; the database table is a mirror kept in
 * step by `platform:permissions:sync` so that roles can reference permissions
 * with real foreign keys and the admin UI can list them.
 */
final readonly class PermissionDefinition
{
    public function __construct(
        public string $slug,
        public string $group,
        public RoleScope $scope,
        /**
         * When true, an operator granting this permission is performing a
         * high-risk change: the UI requires re-confirmation and the grant is
         * always audited with a reason.
         */
        public bool $highRisk = false,
        /**
         * Slug of the module that declared this permission; null for core.
         */
        public ?string $module = null,
    ) {}

    /**
     * Where the wording lives.
     *
     * The slug is the key **inside** that array rather than part of the
     * dotted path, because a slug has dots of its own: asking the
     * translator for `access.permissions.crm.customers.view.label` is
     * asking it to walk five levels of nesting rather than to find one key
     * called `crm.customers.view`. `PermissionNames` reads the array and
     * indexes it by slug.
     */
    public function wordingKey(): string
    {
        return 'access.permissions';
    }
}
