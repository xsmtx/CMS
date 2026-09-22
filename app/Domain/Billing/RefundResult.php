<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What a gateway said when asked to send money back.
 */
final readonly class RefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $accepted,
        public ?string $reference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function accepted(string $reference, array $raw = []): self
    {
        return new self(true, $reference, null, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function refused(string $reason, array $raw = []): self
    {
        return new self(false, null, $reason, $raw);
    }
}
