<?php

declare(strict_types=1);

namespace App\Application\Notifications\Listeners;

use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Domain\Billing\Events\InvoiceIssued;
use App\Domain\Billing\Events\PaymentFailed;
use App\Domain\Billing\Events\PaymentReceived;
use App\Domain\Domains\Events\DomainRegistered;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Ordering\Events\OrderPaid;
use App\Domain\Provisioning\Events\ServiceProvisioned;
use App\Domain\Provisioning\Events\ServiceSuspended;
use App\Domain\Provisioning\Events\ServiceTerminated;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns the things that happened into the things people are told.
 *
 * One listener for the events that share a shape — something happened to a
 * record a customer owns, and that customer should hear about it. Nine
 * near-identical listeners would drift: the opt-out check would be in eight
 * of them and the data keys would disagree.
 *
 * Every handler does the same three things: find the record, build the
 * data the template will substitute, and hand both to the `Notifier`. None
 * of them decides who gets it, in what language, or whether they opted out
 * — that is the `Notifier`'s and `ResolveRecipients`' business, settled in
 * one place.
 */
final readonly class SendEventNotifications
{
    public function __construct(
        private Notifier $notifier,
        private ResolveRecipients $recipients,
    ) {}

    public function orderPaid(OrderPaid $event): void
    {
        $order = $this->find(Order::query(), $event->orderId);

        if (! $order instanceof Order || ! $order->customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::OrderPaid,
            $this->recipients->forCustomer($order->customer, NotificationEvent::OrderPaid),
            [
                'order_number' => $order->number,
                'total' => $order->total->format(app()->getLocale()),
            ],
            url('/client/orders/'.$order->number),
            organizationId: $event->organizationId,
        );
    }

    public function invoiceIssued(InvoiceIssued $event): void
    {
        $invoice = $this->find(Invoice::query(), $event->invoiceId);

        if (! $invoice instanceof Invoice || ! $invoice->customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::InvoiceIssued,
            $this->recipients->forCustomer($invoice->customer, NotificationEvent::InvoiceIssued),
            [
                'invoice_number' => $invoice->number,
                'total' => $invoice->total->format(app()->getLocale()),
                'due_date' => $invoice->due_on?->toDateString() ?? '',
            ],
            url('/client/billing/invoices/'.$invoice->number),
            organizationId: $event->organizationId,
        );
    }

    public function paymentReceived(PaymentReceived $event): void
    {
        $payment = $this->find(Payment::query(), $event->paymentId);
        $invoice = $this->find(Invoice::query(), $event->invoiceId);

        if (! $payment instanceof Payment || ! $invoice instanceof Invoice) {
            return;
        }

        $customer = $invoice->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::PaymentReceived,
            $this->recipients->forCustomer($customer, NotificationEvent::PaymentReceived),
            [
                'invoice_number' => $invoice->number,
                'amount' => $payment->amount->format(app()->getLocale()),
            ],
            url('/client/billing/invoices/'.$invoice->number),
            organizationId: $event->organizationId,
        );
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $payment = $this->find(Payment::query(), $event->paymentId);

        if (! $payment instanceof Payment) {
            return;
        }

        $invoice = $payment->invoice;
        $customer = $invoice?->customer;

        if (! $customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::PaymentFailed,
            $this->recipients->forCustomer($customer, NotificationEvent::PaymentFailed),
            [
                'invoice_number' => $invoice === null ? '' : $invoice->number,
                'amount' => $payment->amount->format(app()->getLocale()),
                // The provider's wording, not ours. "Your card was
                // declined" from the bank is more use than a generic line.
                'reason' => $event->reason ?? '',
            ],
            $invoice === null ? null : url('/client/billing/invoices/'.$invoice->number),
            organizationId: $event->organizationId,
        );
    }

    public function serviceProvisioned(ServiceProvisioned $event): void
    {
        $service = $this->find(Service::query(), $event->serviceId);

        if (! $service instanceof Service || ! $service->customer instanceof Customer) {
            return;
        }

        // The credentials are deliberately **not** in the message. They are
        // on the service screen behind a sign-in; an email that carries a
        // control panel password is a password in somebody's inbox forever.
        $this->notifier->send(
            NotificationEvent::ServiceProvisioned,
            $this->recipients->forCustomer($service->customer, NotificationEvent::ServiceProvisioned),
            [
                'service_name' => $service->name,
                'domain' => $service->domain ?? '',
            ],
            url('/client/services/'.$service->id),
            organizationId: $event->organizationId,
        );
    }

    public function serviceSuspended(ServiceSuspended $event): void
    {
        $service = $this->find(Service::query(), $event->serviceId);

        if (! $service instanceof Service || ! $service->customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::ServiceSuspended,
            $this->recipients->forCustomer($service->customer, NotificationEvent::ServiceSuspended),
            [
                'service_name' => $service->name,
                'reason' => $event->reason ?? '',
            ],
            url('/client/services/'.$service->id),
            organizationId: $event->organizationId,
        );
    }

    public function serviceTerminated(ServiceTerminated $event): void
    {
        $service = $this->find(Service::query(), $event->serviceId);

        if (! $service instanceof Service || ! $service->customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::ServiceTerminated,
            $this->recipients->forCustomer($service->customer, NotificationEvent::ServiceTerminated),
            ['service_name' => $service->name],
            organizationId: $event->organizationId,
        );
    }

    public function domainRegistered(DomainRegistered $event): void
    {
        $domain = $this->find(Domain::query(), $event->domainId);

        if (! $domain instanceof Domain || ! $domain->customer instanceof Customer) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::DomainRegistered,
            $this->recipients->forCustomer($domain->customer, NotificationEvent::DomainRegistered),
            [
                'domain' => $domain->name,
                'expires_on' => $domain->expires_on?->toDateString() ?? '',
            ],
            url('/client/domains/'.$domain->id),
            organizationId: $event->organizationId,
        );
    }

    /**
     * Outside the boundary on purpose: a listener runs for the platform,
     * and there is no request to take a boundary from.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private function find(Builder $query, ?string $id): ?Model
    {
        return $id === null ? null : $query->withoutGlobalScope('organization')->find($id);
    }
}
