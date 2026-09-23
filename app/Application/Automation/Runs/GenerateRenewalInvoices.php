<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Billing\IssueInvoice;
use App\Application\Shared\AllocateNumber;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Raises the invoice for everything about to renew.
 *
 * **The guard is a column, not a date calculation.** A service carries
 * `renewal_invoiced_through`, meaning "we have invoiced this one up to
 * here". The question is "which services are due within the lead time and
 * have not been invoiced through that date", which is a question about
 * rows: running the task twice in a minute changes nothing the second
 * time, and a scheduler that was down for three days catches up rather
 * than skipping three days of renewals permanently.
 *
 * **One invoice per customer and currency.** A customer with four services
 * renewing the same week gets one document, which is what they expect and
 * what a bank transfer can actually pay. Two currencies cannot share an
 * invoice — money is never converted in this platform
 * ([ADR 0014](../../../docs/adr/0014-money-representation.md)) — so they
 * become two.
 *
 * **Every amount is copied from the service, never re-read from the
 * catalog.** A product repriced in March does not change what an existing
 * service costs, which is the rule the service row already encodes; a
 * renewal that looked up the current price would quietly undo it.
 *
 * The invoice is issued rather than left as a draft, because issuing is
 * what raises `InvoiceIssued` and therefore what tells the customer. An
 * operator who wants to look first turns the task off and runs it by hand.
 */
final class GenerateRenewalInvoices implements AutomationRun
{
    /**
     * Answers to `separate_invoices`, keyed by customer.
     *
     * @var array<string, bool>
     */
    private array $separate = [];

    public function __construct(
        private readonly OrganizationContext $organizations,
        private readonly AllocateNumber $numbers,
        private readonly IssueInvoice $issuer,
    ) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;
        $horizon = CarbonImmutable::now()->addDays($this->leadDays());

        $groups = [];

        foreach ($this->dueServices($horizon) as $service) {
            $summary = $summary->examining();
            $groups[$this->groupFor($service->customer_id, $service->currency_code, $service->id)]['services'][] = $service;
        }

        foreach ($this->dueDomains($horizon) as $domain) {
            $summary = $summary->examining();
            $groups[$this->groupFor($domain->customer_id, $domain->currency_code, $domain->id)]['domains'][] = $domain;
        }

        foreach ($groups as $group) {
            $summary = $summary->merge($this->invoiceFor(
                $group['services'] ?? [],
                $group['domains'] ?? [],
            ));
        }

        return $summary;
    }

    /**
     * Which invoice a renewal belongs on.
     *
     * One per customer and currency by default: a customer with four
     * services renewing on the same day gets one invoice and pays once.
     * Never across currencies — money is not converted in this platform.
     *
     * A customer who asked for separate invoices gets the item's own id in
     * the key instead, which is a real request from anybody who passes
     * each line to a different cost centre.
     */
    private function groupFor(string $customerId, string $currency, string $itemId): string
    {
        $key = $customerId.'|'.$currency;

        return $this->wantsSeparateInvoices($customerId) ? $key.'|'.$itemId : $key;
    }

    /**
     * Asked once per customer per run, not once per line.
     */
    private function wantsSeparateInvoices(string $customerId): bool
    {
        if (! array_key_exists($customerId, $this->separate)) {
            $customer = $this->organizations->withoutBoundary(
                static fn (): ?Customer => Customer::query()->whereKey($customerId)->first(),
            );

            $this->separate[$customerId] = $customer instanceof Customer && $customer->separate_invoices;
        }

        return $this->separate[$customerId];
    }

    /**
     * @param  list<Service>  $services
     * @param  list<Domain>  $domains
     */
    private function invoiceFor(array $services, array $domains): RunSummary
    {
        $first = $services[0] ?? $domains[0] ?? null;

        if ($first === null) {
            return new RunSummary;
        }

        $label = implode(', ', [
            ...array_map(static fn (Service $service): string => $service->name, $services),
            ...array_map(static fn (Domain $domain): string => $domain->name, $domains),
        ]);

        try {
            $invoice = $this->organizations->withoutBoundary(
                fn (): Invoice => DB::transaction(
                    fn (): Invoice => $this->write($first, $services, $domains),
                ),
            );

            // Outside the transaction: issuing dispatches an event, and
            // nothing is dispatched from inside one (ADR 0027).
            $this->organizations->withoutBoundary(fn (): Invoice => $this->issuer->handle($invoice));

            return (new RunSummary)->changing(new RunItem(
                ItemOutcome::Changed,
                Invoice::class,
                $invoice->id,
                $invoice->refresh()->number,
                $label,
            ));
        } catch (Throwable $exception) {
            return (new RunSummary)->failing(new RunItem(
                ItemOutcome::Failed,
                Service::class,
                $first->id,
                $label,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @param  list<Service>  $services
     * @param  list<Domain>  $domains
     */
    private function write(Service|Domain $first, array $services, array $domains): Invoice
    {
        $currency = $first->currency_code;

        $invoice = Invoice::query()->create([
            'organization_id' => $first->organization_id,
            'number' => 'DRAFT-'.Str::upper(Str::random(10)),
            'customer_id' => $first->customer_id,
            'status' => InvoiceStatus::Draft->value,
            'currency_code' => $currency,
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'due_on' => $this->dueDateFor($services, $domains),
        ]);

        $subtotal = Money::zero($currency);
        $position = 0;

        foreach ($services as $service) {
            $subtotal = $subtotal->plus($service->recurring);

            $this->line(
                $invoice,
                $service->name,
                $service->recurring,
                $service->next_due_on,
                $service->billing_cycle?->nextDueDate($service->next_due_on ?? CarbonImmutable::now()),
                Service::class,
                $service->id,
                $position++,
            );

            // Written before the invoice is issued: a crash after this
            // point leaves an invoice nobody sent, which is recoverable. A
            // crash before it would invoice the same term twice.
            $service->forceFill(['renewal_invoiced_through' => $service->next_due_on])->save();
        }

        foreach ($domains as $domain) {
            $subtotal = $subtotal->plus($domain->renewal);

            $this->line(
                $invoice,
                $domain->name,
                $domain->renewal,
                $domain->expires_on,
                $domain->expires_on?->addYear(),
                Domain::class,
                $domain->id,
                $position++,
            );

            $domain->forceFill(['renewal_invoiced_through' => $domain->expires_on])->save();
        }

        // No tax here on purpose. Tax is a contract with a dull default
        // (ADR 0022) and a renewal is priced exactly as the original was;
        // applying a rate that changed since would produce a total the
        // customer never agreed to.
        $invoice->forceFill([
            'number' => $this->numbers->handle(
                $invoice->organization_id,
                'invoice',
                (string) config('platform.billing.numbering.invoice_prefix', 'INV-'),
                (int) config('platform.billing.numbering.padding', 6),
            ),
            'subtotal_minor' => $subtotal->minorUnits,
            'total_minor' => $subtotal->minorUnits,
        ])->save();

        return $invoice;
    }

    private function line(
        Invoice $invoice,
        string $description,
        Money $amount,
        ?CarbonImmutable $periodStart,
        ?CarbonImmutable $periodEnd,
        string $subjectType,
        string $subjectId,
        int $position,
    ): void {
        InvoiceItem::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            // What this line renews. The description is prose; this is the
            // way back to the row whose date a payment advances.
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'quantity' => 1,
            'currency_code' => $invoice->currency_code,
            'unit_amount_minor' => $amount->minorUnits,
            'line_amount_minor' => $amount->minorUnits,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'period_start' => $periodStart?->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
            'position' => $position,
        ]);
    }

    /**
     * The earliest renewal in the group.
     *
     * An invoice covering a service due on the 3rd and one due on the 27th
     * is due on the 3rd: the later one is being paid early, which is the
     * point of grouping.
     *
     * @param  list<Service>  $services
     * @param  list<Domain>  $domains
     */
    private function dueDateFor(array $services, array $domains): string
    {
        $dates = [
            ...array_map(static fn (Service $s): ?CarbonImmutable => $s->next_due_on, $services),
            ...array_map(static fn (Domain $d): ?CarbonImmutable => $d->expires_on, $domains),
        ];

        $dates = array_values(array_filter($dates));

        if ($dates === []) {
            return CarbonImmutable::now()->toDateString();
        }

        usort($dates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $dates[0]->toDateString();
    }

    /**
     * @return list<Service>
     */
    private function dueServices(CarbonImmutable $horizon): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Service::query()
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->whereNotNull('next_due_on')
                ->whereNotNull('billing_cycle')
                ->whereNot('billing_cycle', BillingCycle::OneTime->value)
                ->whereDate('next_due_on', '<=', $horizon->toDateString())
                ->where(function ($query): void {
                    $query->whereNull('renewal_invoiced_through')
                        ->orWhereColumn('renewal_invoiced_through', '<', 'next_due_on');
                })
                ->orderBy('customer_id')
                ->get()
                ->all()),
        );
    }

    /**
     * @return list<Domain>
     */
    private function dueDomains(CarbonImmutable $horizon): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Domain::query()
                ->where('auto_renew', true)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', $horizon->toDateString())
                ->where(function ($query): void {
                    $query->whereNull('renewal_invoiced_through')
                        ->orWhereColumn('renewal_invoiced_through', '<', 'expires_on');
                })
                ->orderBy('customer_id')
                ->get()
                ->all()),
        );
    }

    private function leadDays(): int
    {
        return (int) config('platform.automation.renewal_lead_days', 14);
    }
}
