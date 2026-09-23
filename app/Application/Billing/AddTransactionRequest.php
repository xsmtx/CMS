<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * What an operator writes down off a bank statement.
 *
 * Two amounts rather than one signed amount, because that is how a
 * statement reads and how an operator copying one thinks: a line has a
 * credit column and a debit column, and asking somebody to type a minus
 * sign is asking them to forget it.
 *
 * Exactly one of them is positive. Both, or neither, is refused rather
 * than netted — a row that means two things is a row nobody can reconcile.
 */
final readonly class AddTransactionRequest
{
    /**
     * @param  list<string>  $invoiceIds  Invoices this movement settles, in the order it is applied to them.
     */
    public function __construct(
        public Money $amountIn,
        public Money $amountOut,
        public CarbonImmutable $occurredAt,
        public array $invoiceIds = [],
        public bool $toCreditBalance = false,
        public string $gateway = 'manual',
        public ?string $reference = null,
        public ?string $description = null,
        public ?Money $fees = null,
        public ?string $recordedBy = null,
    ) {}

    public function isIncoming(): bool
    {
        return $this->amountIn->isPositive();
    }

    /**
     * The one amount that is actually moving.
     */
    public function amount(): Money
    {
        return $this->isIncoming() ? $this->amountIn : $this->amountOut;
    }
}
