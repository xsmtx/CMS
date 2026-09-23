<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * What the registry said about a name.
 *
 * Three answers, not two. **`Unknown` is the important one**: a registry
 * that did not respond has not said a name is free, and an adapter that
 * collapses "I could not ask" into "available" is how a platform sells
 * somebody a domain that already belongs to a lawyer.
 */
final readonly class AvailabilityResult
{
    private function __construct(
        public DomainName $name,
        public bool $available,
        public bool $known,
        public bool $premium = false,
        public ?string $message = null,
    ) {}

    public static function available(DomainName $name, bool $premium = false): self
    {
        return new self($name, true, true, $premium);
    }

    public static function taken(DomainName $name): self
    {
        return new self($name, false, true);
    }

    public static function unknown(DomainName $name, string $message): self
    {
        return new self($name, false, false, message: $message);
    }

    public function isAvailable(): bool
    {
        return $this->known && $this->available;
    }
}
