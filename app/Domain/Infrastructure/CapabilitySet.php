<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * What one adapter declares it can do.
 *
 * A set rather than a list, so that asking twice is cheap and asking about
 * something absent is an answer rather than a search. The Adapters screen
 * renders these grouped by area, which is why `areas()` exists here rather than
 * being recomputed in a presenter.
 *
 * The important method is `writes()`. Core never asks "is this adapter
 * writable"; it asks "may this adapter do this specific thing", because an
 * adapter that can drain a load balancer backend and cannot reboot a machine is
 * the normal case rather than an edge one.
 */
final readonly class CapabilitySet
{
    /** @var array<string, Capability> */
    private array $capabilities;

    public function __construct(Capability ...$capabilities)
    {
        $indexed = [];

        foreach ($capabilities as $capability) {
            $indexed[$capability->value] = $capability;
        }

        $this->capabilities = $indexed;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    public static function of(array $capabilities): self
    {
        return new self(...$capabilities);
    }

    /**
     * @param  list<string>  $values
     */
    public static function fromValues(array $values): self
    {
        return new self(...array_filter(array_map(Capability::tryFrom(...), $values)));
    }

    public function has(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * Everything this adapter would change out there.
     *
     * Read by the Adapters screen to answer the one question an operator has
     * before turning writes on: what exactly am I allowing.
     *
     * @return list<Capability>
     */
    public function writes(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            static fn (Capability $capability): bool => $capability->isWrite(),
        ));
    }

    /**
     * @return list<Capability>
     */
    public function reads(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            static fn (Capability $capability): bool => ! $capability->isWrite(),
        ));
    }

    /**
     * @return list<AdapterArea>
     */
    public function areas(): array
    {
        $areas = [];

        foreach ($this->capabilities as $capability) {
            $areas[$capability->area()->value] = $capability->area();
        }

        return array_values($areas);
    }

    /**
     * The same set with every write removed.
     *
     * This is how an adapter whose row has `writes_enabled = false` is
     * presented to the rest of the platform: not as a refusal at the moment of
     * calling, but as an adapter that never claimed the capability in the first
     * place. A screen then cannot offer a button that would be refused, which
     * is the difference between a guard and a trap.
     */
    public function readOnly(): self
    {
        return self::of($this->reads());
    }

    /**
     * @return list<string>
     */
    public function toValues(): array
    {
        return array_keys($this->capabilities);
    }
}
