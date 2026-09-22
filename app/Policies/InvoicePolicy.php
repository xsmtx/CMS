<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class InvoicePolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('billing.invoices.view');
    }

    public function view(StaffUser $actor, Invoice $invoice): bool
    {
        return $actor->hasPermissionTo('billing.invoices.view')
            && $this->withinBoundary($actor, $invoice);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('billing.invoices.manage');
    }

    /**
     * Issuing, cancelling and editing a draft are the same capability: all
     * three decide what the document says before it is a document.
     */
    public function update(StaffUser $actor, Invoice $invoice): bool
    {
        return $actor->hasPermissionTo('billing.invoices.manage')
            && $this->withinBoundary($actor, $invoice);
    }

    /**
     * Saying money arrived is not the same as managing a document, and an
     * operator who can do one need not be able to do the other.
     */
    public function recordPayment(StaffUser $actor, Invoice $invoice): bool
    {
        return $actor->hasPermissionTo('billing.payments.record')
            && $this->withinBoundary($actor, $invoice);
    }

    public function refund(StaffUser $actor, Invoice $invoice): bool
    {
        return $actor->hasPermissionTo('billing.refunds.manage')
            && $this->withinBoundary($actor, $invoice);
    }

    public function credit(StaffUser $actor, Invoice $invoice): bool
    {
        return $actor->hasPermissionTo('billing.credits.manage')
            && $this->withinBoundary($actor, $invoice);
    }
}
