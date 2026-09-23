<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Application\Import\LegacyValues;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A legacy hosting account becomes a service.
 *
 * This is the domain that matters most, because it carries the money the
 * customer is actually paying — the recurring amount and the next due date. A
 * product imported without prices is an inconvenience; a service imported with
 * the wrong cycle bills somebody wrongly forever.
 *
 * So an unrecognised billing cycle is a **failure**, not a default. A legacy
 * cycle this platform cannot bill is a row an operator has to look at, and
 * guessing "monthly" for an annual account would undercharge them by a factor
 * of twelve without anybody noticing until the renewal.
 *
 * **No server, and no provisioning.** The imported service is a copy of an
 * account that already exists on a machine; attaching it to a server here would
 * invite a sync that renames it, and provisioning it would create a second one.
 * `external_id` keeps the legacy identifier so an operator can reconcile by
 * hand, and the service is deliberately not `provisioning`.
 *
 * `renewal_invoiced_through` is set to the next due date on purpose: it is the
 * guard that stops the renewal sweep raising an invoice for a period the legacy
 * system has already billed. Without it, the first nightly run after an import
 * invoices every customer again.
 */
final readonly class ServiceMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Services;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $clientId = $record->text('userid');
        $customerId = $writer->mappedId(ImportDomain::Customers, $clientId);

        if ($customerId === null) {
            return ImportResult::failed("Its client [{$clientId}] was not imported.");
        }

        $cycle = LegacyValues::cycle($record->text('billingcycle'));

        if ($cycle === null) {
            $raw = $record->text('billingcycle', '(empty)');

            return ImportResult::failed("Its billing cycle [{$raw}] is not one this platform can bill.");
        }

        $customer = Customer::query()->withoutGlobalScope('organization')->find($customerId);

        if ($customer === null) {
            return ImportResult::failed('Its customer no longer exists.');
        }

        $currency = LegacyValues::currency($record->text('currency'), $customer->currency_code);

        if ($currency === null) {
            return ImportResult::failed('Its currency is not one this platform knows.');
        }

        $productId = $writer->mappedId(ImportDomain::Products, $record->text('packageid'));

        $recurring = LegacyValues::money($record->decimal('amount'), $currency);
        $nextDue = LegacyValues::date($record->get('nextduedate'));

        return $writer->create($this->domain(), $record->externalId, fn (): Model => $this->organizations->runAs(
            $customer->organization_id,
            fn (): Service => Service::query()->create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                // Null when the product did not come across. A service with no
                // product still bills correctly, which is what matters; a
                // service with the wrong product does not.
                'product_id' => $productId,
                'server_id' => null,
                'module' => null,
                'status' => $this->status($record->text('domainstatus')),
                'name' => $record->text('domain') ?: 'Imported service '.$record->externalId,
                'package' => $record->text('packageid') ?: null,
                'billing_cycle' => $cycle->value,
                'currency_code' => $currency,
                'recurring_minor' => $recurring->minorUnits,
                'setup_minor' => 0,
                'domain' => $record->text('domain') ?: null,
                'hostname' => $record->text('domain') ?: null,
                // The legacy identifier, so an operator can reconcile against
                // the control panel by hand.
                'external_id' => $record->externalId,
                'username' => $record->text('username') ?: null,
                // Never the legacy password. It is somebody else's encryption,
                // and a service whose credentials this platform invented would
                // be a service nobody can log into anyway.
                'password' => null,
                'starts_on' => LegacyValues::date($record->get('regdate'))?->toDateString(),
                'next_due_on' => $nextDue?->toDateString(),
                /*
                 * The guard that stops the renewal sweep invoicing a period the
                 * legacy system has already billed. Without it the first
                 * nightly run after an import invoices every customer again,
                 * which is the single most expensive way to get a migration
                 * wrong.
                 */
                'renewal_invoiced_through' => $nextDue?->toDateString(),
                'ends_on' => null,
            ]),
        ));
    }

    private function status(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'active' => ServiceStatus::Active->value,
            'suspended' => ServiceStatus::Suspended->value,
            'terminated' => ServiceStatus::Terminated->value,
            'cancelled' => ServiceStatus::Terminated->value,
            'fraud' => ServiceStatus::Terminated->value,
            // Pending, and anything a legacy system invented. Never
            // `provisioning`: that would say this platform is in the middle of
            // creating an account which already exists.
            default => ServiceStatus::Pending->value,
        };
    }
}
