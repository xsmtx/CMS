<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Every message this platform can send.
 *
 * A closed list rather than free strings, because each member is a template
 * an operator edits, a preference a customer sets and a row in a delivery
 * log somebody reads during a dispute. A typo in a string would produce a
 * message nobody can find, switch off or explain.
 *
 * Adding a member means adding a template and a translation. That friction
 * is the point: a new message to customers is a product decision.
 */
enum NotificationEvent: string
{
    case OrderPlaced = 'order.placed';
    case OrderPaid = 'order.paid';

    case InvoiceIssued = 'invoice.issued';
    case PaymentReceived = 'payment.received';
    case PaymentFailed = 'payment.failed';

    case ServiceProvisioned = 'service.provisioned';
    case ServiceSuspended = 'service.suspended';
    case ServiceTerminated = 'service.terminated';

    case DomainRegistered = 'domain.registered';
    case DomainExpiring = 'domain.expiring';

    case TicketOpened = 'ticket.opened';
    case TicketReplied = 'ticket.replied';

    public function labelKey(): string
    {
        return 'notifications.events.'.$this->translationKey();
    }

    /**
     * The value with its dot replaced.
     *
     * Laravel splits a translation key on dots, so `invoice.issued` nested
     * in a language array is unreachable — it looks for `invoice` then
     * `issued`. The stored value keeps its dot, because it is a public
     * surface that modules and webhooks read; only the lookup changes.
     */
    public function translationKey(): string
    {
        return str_replace('.', '_', $this->value);
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::InvoiceIssued, self::PaymentReceived, self::PaymentFailed => NotificationCategory::Invoices,
            self::TicketOpened, self::TicketReplied => NotificationCategory::Support,
            default => NotificationCategory::Product,
        };
    }

    /**
     * Whether a customer may switch it off.
     *
     * A receipt, a suspension warning and a password reset are part of the
     * service, not marketing. A platform that lets somebody opt out of
     * "your site is about to be turned off" and then turns it off has
     * chosen the wrong side of that argument.
     */
    public function isTransactional(): bool
    {
        return match ($this) {
            self::PaymentFailed, self::ServiceSuspended, self::ServiceTerminated,
            self::DomainExpiring, self::InvoiceIssued => true,
            default => false,
        };
    }

    /**
     * Who hears about it by default.
     */
    public function audience(): NotificationAudience
    {
        return match ($this) {
            self::OrderPlaced => NotificationAudience::Both,
            self::TicketOpened, self::TicketReplied => NotificationAudience::Both,
            default => NotificationAudience::Customer,
        };
    }

    /**
     * @return list<self>
     */
    public static function forCategory(NotificationCategory $category): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $event): bool => $event->category() === $category,
        ));
    }
}
