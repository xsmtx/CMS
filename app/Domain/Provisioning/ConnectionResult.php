<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Whether a node answered, and what it said.
 *
 * `version` is what an operator actually wants from a connection test: not
 * "OK" but "WHM 110.0.17 answered in 240ms", which tells them the
 * credentials work, the firewall is open and they are pointed at the box
 * they think they are.
 */
final readonly class ConnectionResult
{
    public function __construct(
        public bool $reachable,
        public ?string $version = null,
        public ?int $durationMs = null,
        public ?string $message = null,
    ) {}

    public static function ok(?string $version = null, ?int $durationMs = null): self
    {
        return new self(true, $version, $durationMs);
    }

    public static function failed(string $message): self
    {
        return new self(false, message: $message);
    }
}
