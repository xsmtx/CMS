<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Shared\Money;

/**
 * One thing a leakage question found, at one moment (§21).
 *
 * The recorder's currency, and a value object for the same reason
 * `Difference` is: what is stored is the **finding**, and a question that
 * found nothing produces nothing at all — which is what makes the list
 * something an operator can finish reading.
 *
 * `amount` is what is at stake and never a debt. Nobody has been invoiced,
 * so nothing is owed; the figure is what would have been invoiced had
 * anybody asked. The distinction is the reason this never touches the
 * ledger (ADR 0024).
 */
final readonly class Leak
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public LeakageKind $kind,
        public string $subjectType,
        public string $subjectId,
        public string $label,
        public Money $amount,
        public ?string $customerId = null,
        public ?string $customerLabel = null,
        public array $detail = [],
    ) {}
}
