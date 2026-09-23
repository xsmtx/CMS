<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Application\Import\LegacyValues;
use App\Domain\Domains\DomainStatus;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A legacy domain registration becomes a domain.
 *
 * **No registrar, and no registrant.** The registrar is not imported because an
 * imported domain must not be renewed or transferred by this platform until
 * somebody has connected the registrar account it actually lives in — a domain
 * attached to a registrar adapter with no matching account at the registry is a
 * renewal that fails at exactly the wrong moment. The registrant is not
 * imported because it is never stored here at all (ADR 0028): the registry holds
 * it.
 *
 * The name is split at the **first dot**, deliberately, and the extension is
 * stored as it was read rather than matched against the TLDs on sale. An
 * imported `co.uk` domain whose extension this installation does not sell is
 * still a domain the customer owns, and refusing it would lose it; the TLD
 * relationship is left null and an operator connects it when they add the
 * extension.
 *
 * `renewal_invoiced_through` is set to the expiry for the same reason the
 * services get it: so the first nightly sweep after an import does not invoice
 * a renewal the legacy system has already billed.
 */
final readonly class DomainMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Domains;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $clientId = $record->text('userid');
        $customerId = $writer->mappedId(ImportDomain::Customers, $clientId);

        if ($customerId === null) {
            return ImportResult::failed("Its client [{$clientId}] was not imported.");
        }

        $name = strtolower($record->text('domain'));

        if ($name === '' || ! str_contains($name, '.')) {
            return ImportResult::failed('It has no usable domain name.');
        }

        $customer = Customer::query()->withoutGlobalScope('organization')->find($customerId);

        if ($customer === null) {
            return ImportResult::failed('Its customer no longer exists.');
        }

        $currency = LegacyValues::currency($record->text('currency'), $customer->currency_code);

        if ($currency === null) {
            return ImportResult::failed('Its currency is not one this platform knows.');
        }

        $expires = LegacyValues::date($record->get('expirydate'));

        [$label, $extension] = $this->split($name);

        return $writer->create($this->domain(), $record->externalId, fn (): Model => $this->organizations->runAs(
            $customer->organization_id,
            fn (): Domain => Domain::query()->create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                // Left null: an imported extension this installation does not
                // sell is still a domain the customer owns, and refusing it
                // would lose it.
                'tld_id' => null,
                // Never the legacy registrar. A domain attached to an adapter
                // with no matching account at the registry is a renewal that
                // fails at exactly the wrong moment.
                'registrar' => null,
                'status' => $this->status($record->text('status')),
                'label' => $label,
                'extension' => $extension,
                'name' => $name,
                'years' => 1,
                'currency_code' => $currency,
                'renewal_minor' => LegacyValues::money($record->decimal('recurringamount'), $currency)->minorUnits,
                'external_id' => $record->externalId,
                'registered_on' => LegacyValues::date($record->get('registrationdate'))?->toDateString(),
                'expires_on' => $expires?->toDateString(),
                // So the first nightly sweep does not invoice a renewal the
                // legacy system already billed.
                'renewal_invoiced_through' => $expires?->toDateString(),
                'auto_renew' => $record->text('donotrenew') !== '1',
                'order_type' => 'register',
            ]),
        ));
    }

    /**
     * Split at the first dot.
     *
     * `DomainName::parseWithin()` is the correct split when an extension is
     * being *priced*, because it matches against the TLDs on sale. Here nothing
     * is being priced and the TLD may not be on sale at all, so the first dot is
     * the honest answer — and it is recorded as such rather than left to look
     * like a considered match.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $name): array
    {
        $at = strpos($name, '.');

        return $at === false
            ? [$name, '']
            : [substr($name, 0, $at), substr($name, $at + 1)];
    }

    private function status(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'active' => DomainStatus::Active->value,
            'expired' => DomainStatus::Expired->value,
            'cancelled' => DomainStatus::Cancelled->value,
            'transferred away' => DomainStatus::Cancelled->value,
            'fraud' => DomainStatus::Cancelled->value,
            // Pending and anything else. Never `registering` or
            // `transferring`: those say this platform has a request in flight
            // at a registry, and it has not.
            default => DomainStatus::Pending->value,
        };
    }
}
