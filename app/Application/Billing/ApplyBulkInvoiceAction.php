<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Billing\InvoiceBulkAction;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Correlation\CorrelationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One action, applied to invoices the caller has already decided it may touch.
 *
 * **Authorization is not here.** The controller resolves the rows inside the
 * organization boundary and asks the policy about each one, because that is
 * the two questions this platform asks in that order and a use case that
 * answered them again would be a second opinion nobody reconciles.
 *
 * Three rules, and they are the same three every sweep in this platform
 * follows (ADR 0031):
 *
 * - **A row the action cannot apply to is skipped, not refused.** Somebody
 *   who selected twenty invoices and caught one already-cancelled among them
 *   meant the other nineteen.
 * - **One row failing never stops the rest.** A bulk action that stopped on
 *   the eleventh of twenty leaves an operator with no idea which ten went
 *   through.
 * - **What happened is counted and reported**, including the failures, with
 *   the reason sanitised. "Done" is not something an operator can act on.
 *
 * Each row is its own transaction, because `IssueInvoice` opens one of its
 * own and one transaction around twenty invoices is a lock held across
 * twenty number allocations.
 */
final readonly class ApplyBulkInvoiceAction
{
    public function __construct(
        private IssueInvoice $issue,
        private TransitionInvoice $transitions,
        private CorrelationContext $correlation,
    ) {}

    /**
     * @param  iterable<Invoice>  $invoices  already scoped and already authorized
     */
    public function handle(
        iterable $invoices,
        InvoiceBulkAction $action,
        ?Model $actor = null,
        ?string $reason = null,
    ): BulkOutcome {
        $changed = 0;
        $skipped = 0;
        $failures = [];

        foreach ($invoices as $invoice) {
            if (! $action->appliesTo($invoice->status)) {
                $skipped++;

                continue;
            }

            try {
                $this->apply($invoice, $action, $actor, $reason);
                $changed++;
            } catch (Throwable $exception) {
                // The operator gets the invoice's own name and a short
                // reason; the log gets the exception. A stack trace in a
                // flash message is a stack trace in a screenshot.
                $failures[] = $invoice->number ?? $invoice->id;

                Log::warning('billing.invoice.bulk_failed', [
                    'invoice_id' => $invoice->id,
                    'action' => $action->value,
                    'correlation_id' => $this->correlation->id(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return new BulkOutcome($changed, $skipped, $failures);
    }

    private function apply(
        Invoice $invoice,
        InvoiceBulkAction $action,
        ?Model $actor,
        ?string $reason,
    ): void {
        match ($action) {
            InvoiceBulkAction::Issue => $this->issue->handle($invoice, $actor),
            InvoiceBulkAction::Cancel => $this->transitions->handle(
                $invoice,
                InvoiceStatus::Cancelled,
                $actor,
                $reason,
            ),
        };
    }
}
