<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * What an import can bring across.
 *
 * **The order of the cases is the order they must run in**, and that is load
 * bearing rather than tidy: a service belongs to a customer and an invoice line
 * belongs to an invoice, so a domain whose parent has not been imported yet has
 * nothing to attach to. `ordered()` is the only place that order is stated, and
 * every caller reads it from here.
 *
 * Deliberately eight, matching the handoff. Not "everything in the legacy
 * database" — an import that carried across a legacy system's own audit log or
 * its module configuration would be importing that system's *implementation*,
 * which cannot mean anything here.
 */
enum ImportDomain: string
{
    case Customers = 'customers';
    case Contacts = 'contacts';
    case Products = 'products';
    case Services = 'services';
    case Domains = 'domains';
    case Invoices = 'invoices';
    case Transactions = 'transactions';
    case Tickets = 'tickets';

    /**
     * Dependency order. Read from here, never re-stated.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }

    public function labelKey(): string
    {
        return 'import.domains.'.$this->value;
    }

    /**
     * What this domain cannot be imported without.
     *
     * **Hard parents only**, and the distinction matters. A service with no
     * product still bills correctly — `ServiceMapper` writes a null
     * `product_id` deliberately, because a service with the *wrong* product is
     * worse than one with none — so products are not listed here even though
     * services run after them. A transaction with no invoice is likewise a real
     * thing: a legacy system records credit top-ups against no invoice at all.
     *
     * A hard parent is one whose absence makes the row impossible rather than
     * incomplete, and there is only one of those: a record belongs to a
     * customer or it belongs to nobody.
     *
     * Listing the soft parents here would refuse "customers and services only",
     * which is a perfectly ordinary thing to run — and refusing an import an
     * operator legitimately wants is as bad as running one that produces
     * orphans.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::Customers, self::Products => [],
            self::Contacts,
            self::Services,
            self::Domains,
            self::Invoices,
            self::Transactions,
            self::Tickets => [self::Customers],
        };
    }
}
