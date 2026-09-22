<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\UnknownPermission;
use LogicException;

/**
 * The in-memory catalogue of every capability the running installation knows
 * about: core permissions plus whatever enabled modules contribute.
 *
 * Deliberately free of framework dependencies so that the catalogue can be
 * unit tested, diffed between releases and reasoned about without booting the
 * application.
 */
final class PermissionRegistry
{
    /** @var array<string, PermissionDefinition> */
    private array $definitions = [];

    /**
     * @param  iterable<PermissionDefinition>  $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    /**
     * Registering the same slug twice is a programming error: two features
     * silently sharing one capability is exactly the kind of authorization
     * bug that is impossible to spot in review.
     */
    public function register(PermissionDefinition $definition): void
    {
        if (isset($this->definitions[$definition->slug])) {
            throw new LogicException(
                "Permission [{$definition->slug}] is already registered by ".
                ($this->definitions[$definition->slug]->module ?? 'core').'.'
            );
        }

        $this->definitions[$definition->slug] = $definition;
    }

    public function has(string $slug): bool
    {
        return isset($this->definitions[$slug]);
    }

    public function get(string $slug): PermissionDefinition
    {
        return $this->definitions[$slug] ?? throw UnknownPermission::slug($slug);
    }

    /**
     * @return array<string, PermissionDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @return array<string, PermissionDefinition>
     */
    public function forScope(RoleScope $scope): array
    {
        return array_filter(
            $this->definitions,
            static fn (PermissionDefinition $definition): bool => $definition->scope === $scope,
        );
    }

    /**
     * @return array<string, array<string, PermissionDefinition>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->definitions as $slug => $definition) {
            $grouped[$definition->group][$slug] = $definition;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Remove every permission contributed by a module. Called when a module
     * is disabled or uninstalled.
     */
    public function forgetModule(string $module): void
    {
        $this->definitions = array_filter(
            $this->definitions,
            static fn (PermissionDefinition $definition): bool => $definition->module !== $module,
        );
    }
}
