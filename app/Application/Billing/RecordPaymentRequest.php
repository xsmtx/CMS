<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * What is known about money that arrived.
 *
 * `idempotencyKey` is what stops a webhook delivered twice, or an operator
 * double-clicking, from recording two payments: the column is unique.
 */
final readonly class RecordPaymentRequest
{
    public function __construct(
        public Money $amount,
        public string $gateway = 'manual',
        public ?string $reference = null,
        public ?string $idempotencyKey = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?string $recordedBy = null,
        public ?string $note = null,
    ) {}
}
