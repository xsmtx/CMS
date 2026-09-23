<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

/**
 * What a channel managed.
 *
 * A failure is returned rather than thrown: one channel being down must not
 * stop the others, and an exception escaping a notification would fail the
 * operation that triggered it — a customer's payment rolled back because a
 * receipt could not be sent is a worse outcome than a missing receipt.
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public bool $delivered,
        public ?string $reference = null,
        public ?string $error = null,
    ) {}

    public static function delivered(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
