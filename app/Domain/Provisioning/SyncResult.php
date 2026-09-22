<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * What the provider says is true right now.
 *
 * A sync reports; it does not decide. Whether a service that the provider
 * calls suspended should become suspended here is a question for the
 * application layer, which knows why it was suspended and whether anybody
 * here asked for it.
 */
final readonly class SyncResult
{
    /**
     * @param  array<string, mixed>  $usage  Sanitised counters: disk, bandwidth.
     */
    public function __construct(
        public bool $reachable,
        public ?ServiceStatus $remoteStatus = null,
        public array $usage = [],
        public ?string $message = null,
    ) {}

    public static function unreachable(string $message): self
    {
        return new self(false, message: $message);
    }
}
