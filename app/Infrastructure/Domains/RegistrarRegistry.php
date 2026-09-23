<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains;

use App\Domain\Domains\Contracts\DomainRegistrar;

/**
 * The registrars this installation can actually use.
 *
 * The same rule as the gateway and module registries: one without
 * credentials is not registered at all, so the set an operator picks from
 * on a TLD form is the set that works.
 */
final class RegistrarRegistry
{
    /**
     * @var array<string, DomainRegistrar>
     */
    private array $registrars = [];

    public function register(DomainRegistrar $registrar): void
    {
        $this->registrars[$registrar->key()] = $registrar;
    }

    public function find(string $key): ?DomainRegistrar
    {
        return $this->registrars[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->registrars[$key]);
    }

    /**
     * @return list<DomainRegistrar>
     */
    public function all(): array
    {
        return array_values($this->registrars);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->registrars);
    }
}
