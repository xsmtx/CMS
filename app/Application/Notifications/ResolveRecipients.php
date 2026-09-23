<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationRecipient;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;

/**
 * Who should be told.
 *
 * The opt-out is applied here, once, rather than at each call site — and
 * **it is recorded rather than obeyed silently**. A recipient who has
 * switched off a category still produces a delivery row, marked
 * `suppressed`, because "we did not send it" is an answer a support agent
 * needs and an absence is not.
 *
 * A transactional message ignores the preference entirely. A platform that
 * lets somebody opt out of "your site is about to be suspended" and then
 * suspends it has chosen the wrong side of that argument.
 */
final readonly class ResolveRecipients
{
    /**
     * Every contact on a customer who should hear about this event.
     *
     * @return list<NotificationRecipient>
     */
    public function forCustomer(Customer $customer, NotificationEvent $event): array
    {
        $contacts = $customer->contacts()
            ->where('portal_access', true)
            ->get();

        $recipients = [];

        foreach ($contacts as $contact) {
            $recipients[] = $this->forContact($contact, $event);
        }

        return $recipients;
    }

    public function forContact(Contact $contact, NotificationEvent $event): NotificationRecipient
    {
        $column = $event->category()->preferenceColumn();
        $wants = (bool) ($contact->getAttribute($column) ?? true);

        return new NotificationRecipient(
            name: $contact->displayName(),
            email: $contact->email,
            locale: $this->localeFor($contact),
            subjectType: $contact::class,
            subjectId: $contact->id,
            isStaff: false,
            // A transactional message goes out regardless. It is part of
            // the service, not marketing.
            acceptsCategory: $wants || $event->isTransactional(),
        );
    }

    public function forStaff(StaffUser $staff): NotificationRecipient
    {
        return new NotificationRecipient(
            name: $staff->displayName(),
            email: $staff->email,
            locale: $this->localeFor($staff),
            subjectType: $staff::class,
            subjectId: $staff->id,
            isStaff: true,
        );
    }

    /**
     * Staff who should hear about something nobody in particular owns.
     *
     * @return list<NotificationRecipient>
     */
    public function staffFor(NotificationEvent $event): array
    {
        $recipients = [];

        foreach (StaffUser::query()->active()->get() as $staff) {
            $recipients[] = $this->forStaff($staff, $event);
        }

        return $recipients;
    }

    /**
     * A recipient's own language, falling back to the installation's.
     *
     * A message in the wrong language is worse than a plain one, so this is
     * asked of every recipient rather than of the request.
     */
    private function localeFor(Contact|StaffUser $subject): string
    {
        $locale = $subject->locale;

        return is_string($locale) && $locale !== '' ? $locale : (string) app()->getLocale();
    }
}
