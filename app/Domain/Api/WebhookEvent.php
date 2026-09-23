<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * What this platform posts to an operator's endpoint.
 *
 * A separate list from `NotificationEvent` on purpose. A notification is a
 * sentence for a person and its set is chosen by what is worth telling
 * somebody; a webhook is a fact for a machine, and a machine wants the
 * transitions a person would never be emailed about — `service.activated`,
 * `order.fulfilled`. Merging them would mean either emailing people about
 * state changes or hiding state changes from integrations.
 *
 * The values are the handoff's, and they are a public contract: a member is
 * never renamed or repurposed once an integration subscribes to it.
 */
enum WebhookEvent: string
{
    case InvoiceCreated = 'invoice.created';
    case InvoicePaid = 'invoice.paid';
    case InvoiceOverdue = 'invoice.overdue';

    case PaymentCompleted = 'payment.completed';
    case PaymentFailed = 'payment.failed';
    case PaymentRefunded = 'payment.refunded';

    case OrderCreated = 'order.created';
    case OrderPaid = 'order.paid';
    case OrderFulfilled = 'order.fulfilled';

    case ServiceCreated = 'service.created';
    case ServiceActivated = 'service.activated';
    case ServiceSuspended = 'service.suspended';
    case ServiceUnsuspended = 'service.unsuspended';
    case ServiceTerminated = 'service.terminated';

    case DomainRegistered = 'domain.registered';
    case DomainRenewed = 'domain.renewed';
    case DomainExpiring = 'domain.expiring';

    case TicketCreated = 'ticket.created';
    case TicketReplied = 'ticket.replied';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
