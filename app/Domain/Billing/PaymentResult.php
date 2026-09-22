<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What a gateway said when asked to take money.
 *
 * A redirect is not a payment. `redirectUrl` means the customer has to go
 * somewhere; what happened there arrives on a webhook, and nothing is
 * marked paid until it does.
 */
final readonly class PaymentResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentStatus $status,
        public ?string $reference = null,
        public ?string $redirectUrl = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function pending(string $reference, ?string $redirectUrl = null, array $raw = []): self
    {
        return new self(PaymentStatus::Pending, $reference, $redirectUrl, null, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function completed(string $reference, array $raw = []): self
    {
        return new self(PaymentStatus::Completed, $reference, null, null, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $reason, ?string $reference = null, array $raw = []): self
    {
        return new self(PaymentStatus::Failed, $reference, null, $reason, $raw);
    }

    public function needsRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
