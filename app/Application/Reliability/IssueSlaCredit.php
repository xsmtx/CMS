<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Application\Billing\IssueCreditNote;
use App\Application\Shared\ResolveSeller;
use App\Domain\Reliability\Exceptions\CreditRefused;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\SlaCredit;
use App\Support\Audit\Facades\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Giving money back because of an outage (§15).
 *
 * **It is a credit note, and there is no second way to move money here.**
 * ADR 0023 froze the issued invoice and ADR 0024 made the ledger the truth, so
 * a credit for an outage is a numbered document against the invoice for the
 * period — never an edit to a line, never a discount applied retroactively,
 * never a direct write to a balance. This class adds one row saying which
 * incident that credit note was about, and delegates the money entirely.
 *
 * **Core does not work out how much.** An SLA is a contract this platform has
 * never read: 99.9% with a 10% credit is one seller's terms, some sellers
 * credit the day and some the month, some pro-rate to the minute and some
 * refuse below a threshold. A calculator here would be this product inventing
 * a commercial promise on a seller's behalf — the decision tax and dunning
 * already made. The operator states the amount; the platform states who was
 * affected, which is the part only it can answer.
 *
 * **Only a resolved incident.** Crediting one that is still going is agreeing
 * a figure for a duration nobody knows yet, and the first thing an operator
 * would want afterwards is to change it — which a credit note cannot do.
 */
final readonly class IssueSlaCredit
{
    public function __construct(
        private IssueCreditNote $creditNotes,
        private ResolveSeller $sellers,
    ) {}

    public function handle(
        Incident $incident,
        Invoice $invoice,
        Money $amount,
        string $reason,
        ?StaffUser $actor = null,
    ): SlaCredit {
        if ($incident->state->isOpen()) {
            throw CreditRefused::incidentIsOpen($incident->reference);
        }

        if ($invoice->customer_id === null) {
            throw CreditRefused::invoiceHasNoCustomer($invoice->number);
        }

        // The seller credits their own customer's invoice. The boundary
        // already narrows what an operator can load; this says why, so the
        // refusal reads as a rule rather than as a missing record.
        if (! $this->sells($incident, $invoice)) {
            throw CreditRefused::differentSeller();
        }

        if ($this->alreadyCredited($incident, $invoice)) {
            throw CreditRefused::alreadyCredited($invoice->number);
        }

        return DB::transaction(function () use ($incident, $invoice, $amount, $reason, $actor): SlaCredit {
            // Everything that can refuse — the currency, the draft, the
            // amount against what is left of the invoice — is asked by the
            // one class that owns issuing a credit note. A second copy of
            // those rules here is a second copy that would disagree.
            $note = $this->creditNotes->handle($invoice, $amount, $reason, $actor);

            $credit = SlaCredit::query()->create([
                'organization_id' => $incident->organization_id,
                'incident_id' => $incident->id,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'credit_note_id' => $note->id,
                // Copied, and not a cache to rebuild: a report of what
                // incidents cost last quarter must not move because somebody
                // later credited the same invoice for something else.
                'amount_minor' => $amount->minorUnits,
                'currency_code' => $amount->currency->code,
                'reason' => $reason,
                'issued_by' => $actor?->id,
            ]);

            Audit::action('reliability.sla_credit.issued')
                ->by($actor)
                ->on($incident)
                ->forOrganization($incident->organization_id)
                ->because($reason)
                ->withMetadata([
                    'invoice' => $invoice->number,
                    'credit_note' => $note->number,
                    'amount' => $amount->toDecimalString(),
                    'currency' => $amount->currency->code,
                ])
                ->write();

            return $credit;
        });
    }

    /**
     * Whether this invoice is one the incident's owner sells.
     *
     * A customer is an organization of its own, so an invoice belongs to the
     * *buyer* — and "does this seller sell to them" is exactly the question
     * `ResolveSeller` exists to answer once. Asking it by hand here would be
     * the fourth private copy of a boundary escape, which is the thing that
     * class was written to stop.
     */
    private function sells(Incident $incident, Invoice $invoice): bool
    {
        return $this->sellers->forOrganization($invoice->organization_id)
            === $incident->organization_id;
    }

    private function alreadyCredited(Incident $incident, Invoice $invoice): bool
    {
        return SlaCredit::query()
            ->where('incident_id', $incident->id)
            ->where('invoice_id', $invoice->id)
            ->exists();
    }
}
