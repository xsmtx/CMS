<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * Appends to the ledger, and reads totals back out of it.
 *
 * The rows are the truth. An invoice's `paid_minor` and a customer's credit
 * balance are both caches of what is written here, and both are rebuilt
 * from these rows rather than incremented in place — an increment that runs
 * twice is a balance nobody can explain.
 *
 * Every amount stored is positive. The kind decides which way the money
 * moves, so a refund cannot accidentally be recorded as income.
 */
final readonly class Ledger
{
    public function record(
        Customer $customer,
        TransactionKind $kind,
        Money $amount,
        ?Invoice $invoice = null,
        ?Payment $payment = null,
        ?string $description = null,
        ?string $recordedBy = null,
    ): Transaction {
        $credit = $this->creditBalance($customer, $amount->currency->code);

        if ($kind === TransactionKind::CreditAdded) {
            $credit = $credit->plus($amount);
        } elseif ($kind === TransactionKind::CreditApplied) {
            $credit = $credit->minus($amount);
        }

        return Transaction::query()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'payment_id' => $payment?->id,
            'kind' => $kind->value,
            'currency_code' => $amount->currency->code,
            'amount_minor' => abs($amount->minorUnits),
            'credit_balance_minor' => $credit->minorUnits,
            'description' => $description,
            'recorded_by' => $recordedBy,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * What a customer holds in account credit.
     *
     * Read from the most recent row's running balance rather than summed,
     * because the running balance is what the customer was last shown and
     * the two must not be able to disagree.
     */
    public function creditBalance(Customer $customer, string $currencyCode): Money
    {
        $latest = Transaction::query()
            ->where('customer_id', $customer->id)
            ->where('currency_code', strtoupper($currencyCode))
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        return Money::ofMinor(
            $latest instanceof Transaction ? $latest->credit_balance->minorUnits : 0,
            $currencyCode,
        );
    }

    /**
     * What an invoice has actually been paid, according to the rows.
     *
     * Rebuilt rather than incremented: this is the number an operator
     * argues with a customer about, and it has to be derivable from the
     * history rather than trusted because a counter was bumped.
     */
    public function paidTowards(Invoice $invoice): Money
    {
        $total = Money::zero($invoice->currency_code);

        foreach ($invoice->transactions()->get() as $transaction) {
            if ($transaction->kind->touchesCredit() && $transaction->kind !== TransactionKind::CreditApplied) {
                continue;
            }

            $total = $transaction->kind->increasesPaid()
                ? $total->plus($transaction->amount)
                : $total->minus($transaction->amount);
        }

        return $total;
    }
}
