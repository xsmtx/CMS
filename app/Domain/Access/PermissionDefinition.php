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

    public function labelKey(): string
    {
        return 'access.permissions.'.$this->slug.'.label';
    }

    public function descriptionKey(): string
    {
        return 'access.permissions.'.$this->slug.'.description';
    }
}
