<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * The switches a customer actually has.
 *
 * Four, matching the `notify_*` columns `contacts` has carried since Phase
 * 1 with nothing reading them. Per-event preferences would be a screen of
 * twelve checkboxes nobody sets correctly; four categories are what a
 * person can hold in their head.
 */
enum NotificationCategory: string
{
    case Invoices = 'invoices';
    case Support = 'support';
    case Product = 'product';
    case Marketing = 'marketing';

    public function labelKey(): string
    {
        return 'notifications.categories.'.$this->value;
    }

    /**
     * The contact column that switches this off.
     */
    public function preferenceColumn(): string
    {
        return 'notify_'.$this->value;
    }
}
