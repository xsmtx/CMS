<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Billing\TransactionKind;
use App\Domain\Intelligence\Leak;
use App\Domain\Intelligence\LeakageKind;
use App\Domain\Provisioning\AddonStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use Carbon\CarbonImmutable;

/**
 * Four ways money quietly stops arriving (§21).
 *
 * **No adapter, no provider, no model.** Every one of these is a join over
 * rows this platform already owns, which makes this the one family in Phase
 * H whose answers do not depend on an untested parse. A reconciliation
 * finding is only as true as an adapter; these are arithmetic.
 *
 * **Every answer keeps its own currency.** A row is one currency and the
 * summary is a `MoneyByCurrency`, because there is no rate anywhere in this
 * product and a total across currencies is a figure that means nothing — and
 * is exactly the figure somebody would quote.
 *
 * **`renewal_invoiced_through` is the question, not `next_due_on`.** The
 * renewal sweep writes it, the importer writes it, and it is the column that
 * says how far billing has actually got. A service whose next due date has
 * passed is ordinary for a few hours a night; one whose invoiced-through is
 * behind and whose *invoices* do not mention it is the leak.
 *
 * **A finding is never an accusation.** A charity given free hosting, a
 * domain held for a customer arriving in March, a payment taken on account
 * before the invoice was raised — all three are deliberate, and all three
 * appear here. The screen says how much is at stake; an operator says
 * whether it matters, and a dismissal is how they say it once.
 */
final readonly class DetectLeakage
{
    public const string Resource = 'leakage';

    /**
     * How far back an unmatched payment is worth reporting.
     *
     * A payment taken this morning that nobody has allocated yet is a
     * bookkeeper's afternoon, not a leak. Two days is long enough that
     * anything still unattached was not going to be.
     */
    private const int UnmatchedAfterDays = 2;

    /**
     * @return list<Leak>
     */
    public function handle(string $organizationId, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        return [
            ...$this->services($organizationId, $at),
            ...$this->domains($organizationId, $at),
            ...$this->addons($organizationId, $at),
            ...$this->payments($organizationId, $at),
        ];
    }

    /**
     * Active, priced, and nothing has invoiced it since it fell due.
     *
     * A service with no recurring amount is skipped rather than reported:
     * zero means free, which is a price somebody set (ADR 0021's rule that
     * absence and zero say different things).
     *
     * @return list<Leak>
     */
    private function services(string $organizationId, CarbonImmutable $at): array
    {
        $leaks = [];

        foreach (
            Service::query()
                ->withoutGlobalScope('organization')
                ->whereIn('status', [
                    ServiceStatus::Active->value,
                    ServiceStatus::GracePeriod->value,
                ])
                ->where('recurring_minor', '>', 0)
                ->whereNotNull('next_due_on')
                ->where('next_due_on', '<', $at->toDateString())
                ->with(Customer::displayNameWith('customer'))
                ->whereHas('customer', static fn ($query) => $query
                    ->where('organization_id', $organizationId))
                ->limit(2000)
                ->cursor() as $service
        ) {
            // The column that says how far billing actually got. A service
            // invoiced through a date after its due date is simply paid up.
            $due = $service->next_due_on;
            $through = $service->renewal_invoiced_through;

            if ($due === null || ($through !== null && $through->greaterThanOrEqualTo($due))) {
                continue;
            }

            if ($this->invoiced($service::class, $service->id, $due)) {
                continue;
            }

            $leaks[] = new Leak(
                kind: LeakageKind::ServiceNotBilled,
                subjectType: $service::class,
                subjectId: $service->id,
                label: $service->domain ?? $service->name,
                amount: $service->recurring,
                customerId: $service->customer_id,
                customerLabel: $service->customer?->displayName(),
                detail: array_filter([
                    'due' => $due->toDateString(),
                    'cycle' => $service->billing_cycle?->value,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $leaks;
    }

    /**
     * Past its renewal date with no renewal invoice raised.
     *
     * **Only where `auto_renew` is on.** A domain the customer asked not to
     * renew is one this platform is deliberately not billing for, and
     * reporting it would teach an operator that this list is noise.
     *
     * @return list<Leak>
     */
    private function domains(string $organizationId, CarbonImmutable $at): array
    {
        $leaks = [];

        foreach (
            Domain::query()
                ->withoutGlobalScope('organization')
                ->where('auto_renew', true)
                ->where('renewal_minor', '>', 0)
                ->whereNotNull('expires_on')
                ->where('expires_on', '<', $at->toDateString())
                ->with(Customer::displayNameWith('customer'))
                ->whereHas('customer', static fn ($query) => $query
                    ->where('organization_id', $organizationId))
                ->limit(2000)
                ->cursor() as $domain
        ) {
            $expires = $domain->expires_on;
            $through = $domain->renewal_invoiced_through;

            if ($expires === null || ($through !== null && $through->greaterThanOrEqualTo($expires))) {
                continue;
            }

            if ($this->invoiced($domain::class, $domain->id, $expires)) {
                continue;
            }

            $leaks[] = new Leak(
                kind: LeakageKind::DomainNotRenewed,
                subjectType: $domain::class,
                subjectId: $domain->id,
                label: $domain->name,
                amount: $domain->renewal,
                customerId: $domain->customer_id,
                customerLabel: $domain->customer?->displayName(),
                detail: array_filter([
                    'expired' => $expires->toDateString(),
                    'registrar' => $domain->registrar,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $leaks;
    }

    /**
     * On a service that *was* invoiced, and absent from that invoice.
     *
     * The one nobody finds by reading invoices: the parent line is there and
     * looks right, and the extra backup space beside it simply is not. So
     * the question is deliberately narrow — an addon whose parent has been
     * invoiced past its own due date while the addon has not.
     *
     * @return list<Leak>
     */
    private function addons(string $organizationId, CarbonImmutable $at): array
    {
        $leaks = [];

        foreach (
            ServiceAddon::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('status', AddonStatus::Active->value)
                ->where('recurring_minor', '>', 0)
                ->whereNotNull('next_due_on')
                ->where('next_due_on', '<', $at->toDateString())
                ->with(['service' => static fn ($query) => $query->withoutGlobalScope('organization')])
                ->with(Customer::displayNameWith('customer'))
                ->limit(2000)
                ->cursor() as $addon
        ) {
            $due = $addon->next_due_on;
            $through = $addon->renewal_invoiced_through;

            if ($due === null || ($through !== null && $through->greaterThanOrEqualTo($due))) {
                continue;
            }

            $service = $addon->service;

            // Only where the parent *was* billed. An addon on a service
            // nothing is invoicing is the service's own finding, and
            // reporting both would count one problem twice.
            if ($service === null || ! $this->invoiced($service::class, $service->id, $due)) {
                continue;
            }

            if ($this->invoiced($addon::class, $addon->id, $due)) {
                continue;
            }

            $leaks[] = new Leak(
                kind: LeakageKind::AddonNotBilled,
                subjectType: $addon::class,
                subjectId: $addon->id,
                label: $addon->name,
                amount: $addon->recurring,
                customerId: $addon->customer_id,
                customerLabel: $addon->customer?->displayName(),
                detail: array_filter([
                    'service' => $service->domain ?? $service->name,
                    'due' => $due->toDateString(),
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $leaks;
    }

    /**
     * Money received and attached to no invoice.
     *
     * The one that points the other way: the first three are money not asked
     * for, and this is money already taken that no document accounts for — a
     * customer who has paid and may still be chased for it.
     *
     * Read from the **ledger**, not from `payments`: the ledger is the truth
     * about money that moved (ADR 0024), and a payment recorded by an
     * operator and one arriving on a webhook both land there.
     *
     * @return list<Leak>
     */
    private function payments(string $organizationId, CarbonImmutable $at): array
    {
        $leaks = [];
        $cutoff = $at->subDays(self::UnmatchedAfterDays);

        foreach (
            Transaction::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('kind', TransactionKind::Payment->value)
                ->whereNull('invoice_id')
                ->where('occurred_at', '<', $cutoff)
                ->with(Customer::displayNameWith('customer'))
                ->orderByDesc('occurred_at')
                ->limit(500)
                ->get() as $transaction
        ) {
            $leaks[] = new Leak(
                kind: LeakageKind::UnmatchedPayment,
                subjectType: $transaction::class,
                subjectId: $transaction->id,
                label: $transaction->reference ?? $transaction->description ?? $transaction->id,
                amount: $transaction->amount,
                customerId: $transaction->customer_id,
                customerLabel: $transaction->customer?->displayName(),
                detail: array_filter([
                    'gateway' => $transaction->gateway,
                    'received' => $transaction->occurred_at->toDateString(),
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $leaks;
    }

    /**
     * Whether any invoice line has referenced this thing since a date.
     *
     * `invoice_items.subject_type`/`subject_id` is the link, and it is the
     * only honest one: an invoice line copies its description (ADR 0021), so
     * matching on the words would merge two services renamed the same thing
     * and split one renamed last March — the mistake the product revenue
     * report was written to avoid.
     */
    private function invoiced(string $type, string $id, CarbonImmutable $since): bool
    {
        return InvoiceItem::query()
            ->withoutGlobalScope('organization')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            // A line raised before the period began is last month's invoice,
            // not this one's.
            ->where('created_at', '>=', $since->startOfDay())
            ->exists();
    }
}
