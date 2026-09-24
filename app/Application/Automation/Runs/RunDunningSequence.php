<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Billing\ChargeLateFee;
use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Application\Provisioning\RunServiceOperation;
use App\Application\Shared\ResolveSeller;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\DunningAction;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Automation\Models\DunningStep;
use App\Infrastructure\Automation\Models\InvoiceDunningStep;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What happens to an invoice nobody paid.
 *
 * The sequence is rows, not constants
 * ([ADR 0031](../../../docs/adr/0031-a-run-is-a-record.md)): an offset in
 * days, an action, and — for a notify step — which event to raise. A
 * business that never suspends automatically expresses that by having no
 * suspend step, rather than by an operator setting the days to 9999.
 *
 * The question asked is "which owed invoices have a step that has not run
 * against them yet", never "which invoices are exactly seven days old".
 * That is what makes the whole sequence safe to re-run and what lets it
 * catch up after the scheduler was down — and `invoice_dunning_steps` is
 * where the answer lives, with a unique index doing the remembering rather
 * than the code.
 *
 * A step that is due but whose invoice was paid between the query and the
 * write simply finds no owed invoice and does nothing. The row is written
 * after the action succeeds, so a failure is retried on the next sweep
 * rather than silently marked done.
 */
final readonly class RunDunningSequence implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private ResolveSeller $sellers,
        private Notifier $notifier,
        private ResolveRecipients $recipients,
        private RunServiceOperation $services,
        private ChargeLateFee $lateFees,
    ) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        foreach ($this->owedInvoices() as $invoice) {
            $summary = $summary->examining();
            $summary = $summary->merge($this->forInvoice($invoice));
        }

        return $summary;
    }

    private function forInvoice(Invoice $invoice): RunSummary
    {
        $summary = new RunSummary;

        $dueOn = $invoice->due_on;

        if ($dueOn === null) {
            return $summary->skipping();
        }

        $already = $this->stepsAlreadyRun($invoice);
        $acted = false;

        foreach ($this->stepsFor($invoice) as $step) {
            if ($already->contains($step->id) || ! $step->isDueFor($dueOn)) {
                continue;
            }

            if (! $this->allowedBy($step, $invoice)) {
                // Not recorded as run, deliberately. A customer whose
                // notices are turned back on next week should still get
                // the step; a row written here would mean they never do.
                continue;
            }

            try {
                $this->apply($step, $invoice);

                InvoiceDunningStep::query()->create([
                    'organization_id' => $invoice->organization_id,
                    'invoice_id' => $invoice->id,
                    'step_id' => $step->id,
                    'ran_at' => CarbonImmutable::now(),
                ]);

                $acted = true;

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    Invoice::class,
                    $invoice->id,
                    $invoice->number,
                    $step->action->value.' '.($step->offset_days >= 0 ? '+' : '').$step->offset_days,
                ));
            } catch (Throwable $exception) {
                // Not recorded as run, so the next sweep tries again.
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    Invoice::class,
                    $invoice->id,
                    $invoice->number,
                    $exception->getMessage(),
                ));
            }
        }

        return $acted ? $summary : $summary->skipping();
    }

    /**
     * Whether this customer lets this kind of step happen to them.
     *
     * Two preferences, both on the customer record: one for being chased
     * and one for being suspended. A support contract that says "never
     * suspend, we will call them" is a real arrangement, and the
     * alternative to recording it is an operator setting the step to 9999
     * days for everybody.
     *
     * A customer that cannot be read is chased. Silence is not consent to
     * stop collecting money.
     */
    private function allowedBy(DunningStep $step, Invoice $invoice): bool
    {
        $invoice->loadMissing('customer');

        $customer = $invoice->customer;

        if (! $customer instanceof Customer) {
            return true;
        }

        return match ($step->action) {
            DunningAction::Notify => $customer->send_overdue_notices,
            // A fee is a charge, not a withdrawal of service. Reading the
            // suspension preference for it would let "never suspend us" mean
            // "never charge us interest", which is not what anybody agreed to,
            // and reading the notice preference would let opting out of email
            // opt somebody out of the debt.
            DunningAction::LateFee => true,
            DunningAction::Suspend, DunningAction::Terminate => $customer->automatic_suspension,
        };
    }

    private function apply(DunningStep $step, Invoice $invoice): void
    {
        match ($step->action) {
            DunningAction::Notify => $this->notify($step, $invoice),
            DunningAction::LateFee => $this->chargeLateFee($invoice),
            DunningAction::Suspend => $this->suspendServices($invoice),
            DunningAction::Terminate => $this->terminateServices($invoice),
        };
    }

    private function notify(DunningStep $step, Invoice $invoice): void
    {
        $event = $step->notificationEvent() ?? NotificationEvent::InvoiceIssued;

        $invoice->loadMissing('customer');

        $customer = $invoice->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            $event,
            $this->recipients->forCustomer($customer, $event),
            [
                'invoice_number' => $invoice->number,
                'total' => $invoice->total->toDecimalString(),
                'currency' => $invoice->currency_code,
                'due_date' => $invoice->due_on?->toDateString() ?? '',
            ],
            url('/client/invoices/'.$invoice->id),
            organizationId: $invoice->organization_id,
        );
    }

    /**
     * The fee for this invoice, as its own document (ADR 0046).
     *
     * A seller charging no fee, an invoice with nothing outstanding and a fee
     * that rounds to zero all return null, and the step is still recorded as
     * run. That is deliberate: "there was nothing to charge" is an answer, and
     * leaving the step unrecorded would have the sweep ask the same question
     * every night for as long as the debt lives.
     */
    private function chargeLateFee(Invoice $invoice): void
    {
        $this->lateFees->handle($invoice);
    }

    private function suspendServices(Invoice $invoice): void
    {
        foreach ($this->servicesFor($invoice) as $service) {
            if ($service->status !== ServiceStatus::Active) {
                continue;
            }

            $this->services->suspend($service, __('automation.dunning.suspended_reason'));
        }
    }

    private function terminateServices(Invoice $invoice): void
    {
        foreach ($this->servicesFor($invoice) as $service) {
            if ($service->status->isTerminal()) {
                continue;
            }

            $this->services->terminate($service);
        }
    }

    /**
     * The services this invoice was for.
     *
     * Two ways in, because there are two kinds of invoice. A first invoice
     * comes from an order, and its services are the ones that order
     * created. A **renewal** invoice has no order at all — the sweep that
     * raises it writes `subject_type` and `subject_id` onto each line
     * instead — and reading only `order_id` would mean a suspend step
     * quietly finding nothing on exactly the invoices dunning exists for.
     *
     * The line reference is preferred: it names the service directly,
     * where the order names a purchase that may since have been added to.
     *
     * @return list<Service>
     */
    private function servicesFor(Invoice $invoice): array
    {
        $fromLines = $this->organizations->withoutBoundary(
            static fn (): array => array_values(Service::query()
                ->whereIn('id', InvoiceItem::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('subject_type', Service::class)
                    ->select('subject_id'))
                ->get()
                ->all()),
        );

        if ($fromLines !== [] || $invoice->order_id === null) {
            return $fromLines;
        }

        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Service::query()
                ->where('order_id', $invoice->order_id)
                ->get()
                ->all()),
        );
    }

    /**
     * @return Collection<int, DunningStep>
     */
    private function stepsFor(Invoice $invoice): Collection
    {
        $sellerId = $this->sellers->forOrganization($invoice->organization_id);

        /** @var Collection<int, DunningStep> */
        return $this->organizations->withoutBoundary(
            static fn (): Collection => DunningStep::query()
                ->active()
                ->where('organization_id', $sellerId)
                ->get(),
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function stepsAlreadyRun(Invoice $invoice): Collection
    {
        /** @var Collection<int, string> */
        return $this->organizations->withoutBoundary(
            static fn (): Collection => InvoiceDunningStep::query()
                ->where('invoice_id', $invoice->id)
                ->pluck('step_id'),
        );
    }

    /**
     * @return list<Invoice>
     */
    private function owedInvoices(): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Invoice::query()
                ->owed()
                // A late fee is its own invoice (ADR 0046) and is never itself
                // chased: a fee on an unpaid fee compounds nightly, and the
                // debt that matters is still in this query under its own row.
                ->where('is_late_fee', false)
                ->whereNotNull('due_on')
                ->with('customer')
                ->orderBy('due_on')
                ->get()
                ->all()),
        );
    }
}
