<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

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

    private function apply(DunningStep $step, Invoice $invoice): void
    {
        match ($step->action) {
            DunningAction::Notify => $this->notify($step, $invoice),
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
     * Through the order rather than through the invoice lines, because an
     * invoice line is a copy of text and an order item is what a service
     * was created from.
     *
     * @return list<Service>
     */
    private function servicesFor(Invoice $invoice): array
    {
        if ($invoice->order_id === null) {
            return [];
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
                ->whereNotNull('due_on')
                ->with('customer')
                ->orderBy('due_on')
                ->get()
                ->all()),
        );
    }
}
