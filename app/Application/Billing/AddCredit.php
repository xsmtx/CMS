<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Put credit on an account, or take it off.
 *
 * Credit is money. Both directions are ledger rows with a reason, and the
 * capability is separate from the rest of billing for that reason.
 */
final readonly class AddCredit
{
    public function __construct(private Ledger $ledger) {}

    public function handle(
        Customer $customer,
        Money $amount,
        string $reason,
        ?Model $actor = null,
    ): Transaction {
        if (! $amount->isPositive()) {
            throw PaymentRefused::exceedsCredit($amount, $this->ledger->creditBalance($customer, $amount->currency->code));
        }

        $transaction = $this->ledger->record(
            customer: $customer,
            kind: TransactionKind::CreditAdded,
            amount: $amount,
            description: $reason,
            recordedBy: $actor?->getAttribute('email'),
        );

        Audit::action('billing.credit.added')
            ->by($actor)
            ->on($customer)
            ->forOrganization($customer->organization_id)
            ->because($reason)
            ->withMetadata([
                'amount' => $amount->toDecimalString(),
                'currency' => $amount->currency->code,
                'balance' => $transaction->credit_balance->toDecimalString(),
            ])
            ->write();

        return $transaction;
    }
}
