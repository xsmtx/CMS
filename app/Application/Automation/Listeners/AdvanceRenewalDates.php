<?php

declare(strict_types=1);

namespace App\Application\Automation\Listeners;

use App\Domain\Billing\Events\PaymentReceived;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;

/**
 * Moves a service or domain forward once its renewal has been paid.
 *
 * **Payment is the event, not issuing.** An invoice that was raised is a
 * request; an invoice that was paid is the next term being bought. Advancing
 * the date when the invoice is raised would mean an unpaid renewal silently
 * extends the service, which is the bug that makes dunning pointless.
 *
 * The line's `subject_type` and `subject_id` are what make this possible —
 * the description is prose that was already a copy, and parsing it back
 * into a service would be guessing.
 *
 * Idempotent by construction: the new date is computed from the line's
 * `period_end`, which does not move. A payment event delivered twice writes
 * the same date twice.
 */
final readonly class AdvanceRenewalDates
{
    public function __construct(private OrganizationContext $organizations) {}

    public function handle(PaymentReceived $event): void
    {
        $this->organizations->withoutBoundary(function () use ($event): void {
            $invoice = Invoice::query()->find($event->invoiceId);

            if (! $invoice instanceof Invoice || $invoice->status !== InvoiceStatus::Paid) {
                // A part payment buys nothing yet. The next one will raise
                // this event again with the invoice settled.
                return;
            }

            foreach ($this->renewalLines($invoice) as $line) {
                $this->advance($line);
            }
        });
    }

    private function advance(InvoiceItem $line): void
    {
        if ($line->period_end === null) {
            return;
        }

        $subject = match ($line->subject_type) {
            Service::class => Service::query()->find($line->subject_id),
            Domain::class => Domain::query()->find($line->subject_id),
            default => null,
        };

        if ($subject instanceof Service) {
            $subject->forceFill(['next_due_on' => $line->period_end])->save();

            return;
        }

        if ($subject instanceof Domain) {
            // The registry is the authority on a domain's expiry; this is
            // what we believe until the next sync says otherwise.
            $subject->forceFill(['expires_on' => $line->period_end])->save();
        }
    }

    /**
     * @return list<InvoiceItem>
     */
    private function renewalLines(Invoice $invoice): array
    {
        return array_values(InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->whereNotNull('subject_type')
            ->get()
            ->all());
    }
}
