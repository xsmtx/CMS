<?php

declare(strict_types=1);

namespace App\Application\Provisioning\Listeners;

use App\Application\Provisioning\ApplyUpgrade;
use App\Domain\Billing\Events\PaymentReceived;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Provisioning\UpgradeState;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Provisioning\Models\ServiceUpgrade;
use App\Support\Organizations\OrganizationContext;

/**
 * Moves an account once the difference has been paid.
 *
 * **Payment is the event, not issuing** — the rule `AdvanceRenewalDates`
 * states and the reason it listens here too. An upgrade applied when the
 * invoice was raised would be the difference given away to anybody who asked
 * for one and never paid.
 *
 * **A part payment buys nothing yet.** The invoice has to be settled, and the
 * next payment raises this event again with it so.
 *
 * **Idempotent by the state, not by a flag.** `ApplyUpgrade` returns
 * immediately for anything that is not `authorized`, so a payment event
 * delivered twice moves the account once — which is what makes a webhook
 * redelivery safe (ADR 0034).
 *
 * The upgrade is found through its own `invoice_id` rather than through the
 * invoice's lines: a prorated line carries no period on purpose, and parsing a
 * description back into a request would be guessing.
 */
final readonly class ApplyPaidUpgrades
{
    public function __construct(
        private OrganizationContext $organizations,
        private ApplyUpgrade $upgrades,
    ) {}

    public function handle(PaymentReceived $event): void
    {
        $this->organizations->withoutBoundary(function () use ($event): void {
            $invoice = Invoice::query()->find($event->invoiceId);

            if (! $invoice instanceof Invoice || $invoice->status !== InvoiceStatus::Paid) {
                return;
            }

            $upgrades = ServiceUpgrade::query()
                ->with('service')
                ->where('invoice_id', $invoice->id)
                ->where('state', UpgradeState::AwaitingPayment->value)
                ->get();

            foreach ($upgrades as $upgrade) {
                /*
                 * Authorized first, then applied. Two steps rather than one
                 * because the queue screen applies an authorized upgrade too
                 * — a provider that was down when the payment arrived leaves a
                 * row an operator can press, rather than a payment nobody can
                 * act on.
                 */
                $upgrade->state = UpgradeState::Authorized;
                $upgrade->save();

                $this->upgrades->handle($upgrade);
            }
        });
    }
}
