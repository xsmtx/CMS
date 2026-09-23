<?php

declare(strict_types=1);

namespace App\Domain\Modules;

use App\Domain\Modules\Contracts\ModuleLogger;

/**
 * What a module is given, and the complete list of it.
 *
 * Its slug, its own configuration and somewhere to write. No container, no
 * request, no database handle, no event dispatcher — a module that needed
 * one of those would be doing something core should expose deliberately
 * rather than something it can reach for.
 *
 * Configuration comes back already decrypted, because the module is the
 * one thing that legitimately needs the value; it never travels the other
 * way, so a module cannot rewrite its own settings behind an operator's
 * back.
 */
final readonly class ModuleContext
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $slug,
        public ModuleLogger $log,
        private array $config = [],
    ) {}

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->config[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->config[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * Whether everything a field declared as required has a value.
     *
     * Asked by the platform before a module is enabled, so that a module
     * missing its API key is refused with a sentence rather than registered
     * and then failing at the moment a customer is waiting — the same rule
     * the gateway and provisioning registries have always applied.
     *
     * @param  list<ConfigField>  $schema
     * @return list<string> The keys that are missing.
     */
    public function missing(array $schema): array
    {
        $missing = [];

        foreach ($schema as $field) {
            if (! $field->required) {
                continue;
            }

            $value = $this->config[$field->key] ?? null;

            if ($value === null || $value === '') {
                $missing[] = $field->key;
            }
        }

        return $missing;
    }
}
