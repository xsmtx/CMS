<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\InvalidInvoiceTransition;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Moves an invoice from one state to another.
 *
 * The only way the status column changes. Everything goes through here so
 * the state machine is actually enforced and the audit trail never has a
 * gap where someone wrote the column directly.
 */
final readonly class TransitionInvoice
{
    public function handle(
        Invoice $invoice,
        InvoiceStatus $target,
        ?Model $actor = null,
        ?string $reason = null,
    ): Invoice {
        $from = $invoice->status;

        if ($from === $target) {
            return $invoice;
        }

        if (! $from->canTransitionTo($target)) {
            throw InvalidInvoiceTransition::between($from, $target);
        }

        $invoice->forceFill([
            'status' => $target->value,
            // Recorded on the way in rather than derived later: "when was
            // this settled" is a question with one answer.
            'paid_at' => $target === InvoiceStatus::Paid
                ? ($invoice->paid_at ?? CarbonImmutable::now())
                : $invoice->paid_at,
            'cancelled_at' => $target === InvoiceStatus::Cancelled
                ? CarbonImmutable::now()
                : $invoice->cancelled_at,
        ])->save();

        Audit::action('billing.invoice.status_changed')
            ->by($actor)
            ->on($invoice)
            ->forOrganization($invoice->organization_id)
            ->changed(['status' => $from->value], ['status' => $target->value])
            ->because($reason)
            ->write();

        return $invoice;
    }
}
