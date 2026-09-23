<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * What a module actually registered, the last time it was enabled.
 *
 * Kept as data on the row rather than asked of the module, for one reason
 * that decides the shape of the whole lifecycle: **uninstall must be able
 * to refuse without running the module's code.** A guard that had to load a
 * package in order to find out whether it was safe to remove would be
 * running the very thing an operator has decided to be rid of.
 *
 * It is also what the screen shows. "This module provides the Stripe
 * gateway" is a sentence an operator can check against what they expected
 * when they installed it.
 */
final readonly class Registration
{
    /**
     * @param  array<string, list<string>>  $keys  Extension point value => the keys registered there.
     */
    public function __construct(public array $keys = []) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $keys = [];

        foreach ($data as $point => $values) {
            if (! is_string($point) || ExtensionPoint::tryFrom($point) === null || ! is_array($values)) {
                continue;
            }

            $keys[$point] = array_values(array_filter($values, is_string(...)));
        }

        return new self($keys);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->keys;
    }

    /**
     * @return list<ExtensionPoint>
     */
    public function points(): array
    {
        return array_values(array_filter(array_map(
            ExtensionPoint::tryFrom(...),
            array_keys($this->keys),
        )));
    }

    /**
     * @return list<string>
     */
    public function keysFor(ExtensionPoint $point): array
    {
        return $this->keys[$point->value] ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    public function with(ExtensionPoint $point, string $key): self
    {
        $keys = $this->keys;
        $keys[$point->value] = [...($keys[$point->value] ?? []), $key];

        return new self($keys);
    }
}
