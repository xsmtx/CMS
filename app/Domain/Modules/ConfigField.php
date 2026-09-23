<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * One thing a module needs to be told.
 *
 * Declared rather than free-form, so the settings screen can be generated,
 * validated and — for a secret — handled correctly without the module
 * rendering anything itself. A module that drew its own form would be a
 * module that could draw anything.
 */
final readonly class ConfigField
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public ConfigFieldType $type = ConfigFieldType::Text,
        public bool $required = false,
        public ?string $hint = null,
        public string|int|float|bool|null $default = null,
        public array $options = [],
    ) {}

    public function isSecret(): bool
    {
        return $this->type->isSecret();
    }
}
