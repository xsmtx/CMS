<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Billing\TransitionInvoice;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Moves issued invoices past their due date into `overdue`.
 *
 * The simplest task in the phase, and the clearest example of the rule the
 * others follow: the question is "which unpaid invoices have a due date in
 * the past", not "which became due today". A server that was switched off
 * for two days catches up on the next sweep instead of leaving two days of
 * invoices in `unpaid` forever.
 *
 * Nothing is notified here. Telling somebody is the dunning sequence's job,
 * and a status change that also sent an email would make the two impossible
 * to reason about separately.
 */
final readonly class MarkInvoicesOverdue implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private TransitionInvoice $transitions,
    ) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        foreach ($this->candidates() as $invoice) {
            $summary = $summary->examining();

            try {
                if (! $invoice->status->canTransitionTo(InvoiceStatus::Overdue)) {
                    $summary = $summary->skipping();

                    continue;
                }

                $this->transitions->handle($invoice, InvoiceStatus::Overdue, reason: 'automation.overdue');

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    Invoice::class,
                    $invoice->id,
                    $invoice->number,
                ));
            } catch (Throwable $exception) {
                // One invoice failing must not cost the rest of the sweep.
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    Invoice::class,
                    $invoice->id,
                    $invoice->number,
                    $exception->getMessage(),
                ));
            }
        }

        return $summary;
    }

    /**
     * Swept across the whole installation rather than inside one boundary.
     *
     * A scheduled run has no actor and therefore no boundary, and narrowing
     * it to the provider would silently skip every reseller's invoices. The
     * escape is safe because the run reads and writes the same row it found
     * and never crosses from one organization's data to another's.
     *
     * @return list<Invoice>
     */
    private function candidates(): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', CarbonImmutable::now()->toDateString())
                ->oldest('due_on')
                ->get()
                ->all()),
        );
    }
}
