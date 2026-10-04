<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Billing\IssueInvoice;
use App\Application\Provisioning\Exceptions\UpgradeRefused;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Provisioning\UpgradeState;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceUpgrade;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write down a move between plans, and raise the invoice for it.
 *
 * **Every refusal happens before an invoice exists.** A customer who has paid
 * for a move this platform then declines is the one outcome this family must
 * not produce, so the currency, the term, the status and the duplicate check
 * all run first — and `PriceUpgrade` is asked before anything is written.
 *
 * **The invoice is raised directly rather than through an order.** An order
 * creates services and this changes one; pushing it through
 * `CreateServicesForOrder` would mean teaching the fulfilment listener to
 * recognise a line that must *not* become a service. Raising an invoice
 * without an order is what the renewal sweep and the late-fee step already do.
 *
 * **Two lines, and they add up.** The credit for the unused remainder and the
 * charge for the new plan are each on the document, and the total is their
 * sum by construction rather than by arithmetic nobody checked.
 *
 * **A move that costs nothing is authorized immediately.** Two plans at the
 * same price, or a move on the day a term ends, both land there — and both
 * should simply happen rather than produce an invoice for zero.
 *
 * **A downgrade is credited, never refunded.** Money that has been taken is
 * not sent back by a panel. `IssueCreditNote` is this product's one answer to
 * that (ADR 0023), and it is called by the *apply* step rather than here,
 * because a credit for a move that has not happened is money given away for
 * nothing.
 */
final readonly class RequestUpgrade
{
    public function __construct(
        private PriceUpgrade $pricing,
        private IssueInvoice $issue,
    ) {}

    public function handle(
        Service $service,
        Product $target,
        BillingCycle $cycle,
        ?Model $actor = null,
        ?string $note = null,
        ?CarbonImmutable $now = null,
    ): ServiceUpgrade {
        $this->refuseUnlessMovable($service);

        if ($service->product_id === $target->id && $service->billing_cycle === $cycle) {
            throw UpgradeRefused::samePlan();
        }

        if (ServiceUpgrade::query()
            ->where('service_id', $service->id)
            ->whereIn('state', $this->openStates())
            ->exists()) {
            // One at a time. Two open requests would race each other to the
            // provider, and the second was priced against a term the first is
            // about to change.
            throw UpgradeRefused::alreadyRequested();
        }

        $change = $this->pricing->handle($service, $target, $cycle, $now);

        $upgrade = new ServiceUpgrade([
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            // Copied rather than left to the service, which is about to stop
            // saying it.
            'from_product_id' => $service->product_id,
            'from_product_name' => $service->name,
            'from_cycle' => $service->billing_cycle?->value,
            'from_recurring_minor' => $service->recurring->minorUnits,
            'to_product_id' => $target->id,
            'to_product_name' => $target->name,
            'to_cycle' => $cycle->value,
            'to_recurring_minor' => $change->charge->minorUnits,
            'currency_code' => $service->currency_code,
            'credit_minor' => $change->credit->minorUnits,
            'charge_minor' => $change->charge->minorUnits,
            'difference_minor' => $change->difference()->minorUnits,
            'days_remaining' => $change->daysRemaining,
            'term_days' => $change->termDays,
            'restarts_term' => $change->restartsTerm,
            'note' => $note,
        ]);

        $this->attribute($upgrade, $actor);

        /*
         * Nothing to pay is not a state to wait in. A move between two plans
         * at the same price, or one made on the day a term ends, is
         * authorized the moment it is asked for.
         */
        $upgrade->state = $change->isUpgrade()
            ? UpgradeState::AwaitingPayment
            : UpgradeState::Authorized;

        DB::transaction(function () use ($upgrade, $change, $service, $actor): void {
            $upgrade->save();

            if ($change->isUpgrade()) {
                $upgrade->invoice_id = $this->invoiceFor($upgrade, $service)->id;
                $upgrade->save();
            }

            Audit::action('provisioning.upgrade.requested')
                ->by($actor)
                ->on($upgrade)
                ->forOrganization($upgrade->organization_id)
                ->because($upgrade->note)
                ->withMetadata([
                    'service' => $service->name,
                    'to' => $upgrade->to_product_name,
                    // Minor units and a code, never a formatted string: an
                    // audit row read six weeks later wants the figure.
                    'difference_minor' => $upgrade->difference_minor,
                    'currency' => $upgrade->currency_code,
                    'days_remaining' => $upgrade->days_remaining,
                ])
                ->write();
        });

        return $upgrade;
    }

    /**
     * The invoice the customer pays, issued rather than left as a draft.
     *
     * Issued here because there is nothing to edit: the lines are arithmetic
     * and the customer is waiting to pay. A draft would be a move that sat
     * waiting for somebody to press a second button.
     */
    private function invoiceFor(ServiceUpgrade $upgrade, Service $service): Invoice
    {
        $invoice = Invoice::query()->create([
            'organization_id' => $service->organization_id,
            'number' => 'DRAFT-'.Str::upper(Str::random(10)),
            'customer_id' => $service->customer_id,
            'status' => 'draft',
            'currency_code' => $upgrade->currency_code,
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'due_on' => CarbonImmutable::now()->toDateString(),
        ]);

        $this->line(
            $invoice,
            (string) __('provisioning.upgrades.lines.charge', [
                'name' => $upgrade->to_product_name,
                'days' => $upgrade->days_remaining,
            ]),
            Money::ofMinor($upgrade->charge_minor, $upgrade->currency_code),
            $service,
            0,
        );

        /*
         * The credit as a negative line rather than a separate document: it
         * is part of the same transaction and a customer reading the invoice
         * has to see where the figure came from. The two lines sum to the
         * total by construction.
         */
        if ($upgrade->credit_minor > 0) {
            $this->line(
                $invoice,
                (string) __('provisioning.upgrades.lines.credit', [
                    'name' => $upgrade->from_product_name,
                    'days' => $upgrade->days_remaining,
                ]),
                Money::ofMinor(-$upgrade->credit_minor, $upgrade->currency_code),
                $service,
                1,
            );
        }

        /*
         * The totals, written from the lines. `IssueInvoice` freezes a
         * document rather than pricing one, so an invoice created with zeroes
         * and never totalled is an invoice that owes nothing — which reads as
         * a payment refused for being larger than the balance, four layers
         * away from the mistake.
         *
         * No tax, deliberately, and for the reason the renewal sweep gives:
         * a prorated move is priced exactly as the original was, and applying
         * a rate that has changed since would produce a figure the customer
         * never agreed to.
         */
        $total = $upgrade->charge_minor - $upgrade->credit_minor;

        $invoice->forceFill([
            'subtotal_minor' => $total,
            'total_minor' => $total,
        ])->save();

        // `fresh()` is nullable to PHPStan and the row was created above;
        // refetching rather than asserting keeps the type honest.
        return $this->issue->handle(Invoice::query()->findOrFail($invoice->id));
    }

    private function line(Invoice $invoice, string $description, Money $amount, Service $service, int $position): void
    {
        InvoiceItem::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            /*
             * The service, so the line says what it is about — but **no
             * period**, which is what keeps `AdvanceRenewalDates` away from
             * it. A prorated line is not a term being bought, and advancing
             * the renewal date on it would give the customer the remainder
             * of their old term twice.
             */
            'subject_type' => Service::class,
            'subject_id' => $service->id,
            'description' => $description,
            'quantity' => 1,
            'currency_code' => $invoice->currency_code,
            'unit_amount_minor' => $amount->minorUnits,
            'line_amount_minor' => $amount->minorUnits,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'position' => $position,
        ]);
    }

    private function refuseUnlessMovable(Service $service): void
    {
        if ($service->status !== ServiceStatus::Active) {
            // A suspended account can be moved by an operator only after it
            // is back: a provider asked to change the package of a suspended
            // account does something different on every panel there is.
            throw UpgradeRefused::notUpgradable($service->status->value);
        }
    }

    /**
     * Who asked, on the column their kind belongs in.
     *
     * Two nullable columns rather than a polymorphic pair, because there are
     * exactly two kinds of actor in this product and a `*_type` string would
     * be a third thing to validate.
     */
    private function attribute(ServiceUpgrade $upgrade, ?Model $actor): void
    {
        if ($actor === null) {
            return;
        }

        $column = $actor instanceof Contact
            ? 'requested_by_contact'
            : 'requested_by_staff';

        $upgrade->{$column} = $actor->getKey();
    }

    /**
     * @return list<string>
     */
    private function openStates(): array
    {
        return array_values(array_map(
            static fn (UpgradeState $state): string => $state->value,
            array_filter(UpgradeState::cases(), static fn (UpgradeState $state): bool => $state->isOpen()),
        ));
    }
}
