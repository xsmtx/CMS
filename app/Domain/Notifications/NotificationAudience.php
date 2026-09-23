<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

enum NotificationAudience: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Both = 'both';

    public function includesCustomer(): bool
    {
        return $this !== self::Staff;
    }

    public function includesStaff(): bool
    {
        return $this !== self::Customer;
    }
}
