<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What an abuse desk can do to an account (§13).
 *
 * **Every one of these is a person pressing a button.** A platform that
 * suspended a customer because a robot said so would have to be right every
 * time, and no abuse signal is — a shared address, a forwarded newsletter and
 * a competitor's complaint all look like the real thing.
 *
 * Three of the four have no seam in this product yet, and that is stated
 * rather than hidden: `StopOutboundMail` and `ForcePasswordReset` need a
 * provisioning capability nobody has implemented, and `ContactCustomer` is a
 * conversation. They are recorded as decisions and land in `manual`, which is
 * a real outcome and appears on the screen as work somebody still has to do
 * — the `Manual*` adapters' honesty, applied to a decision.
 */
enum AbuseAction: string
{
    case SuspendService = 'suspend_service';
    case StopOutboundMail = 'stop_outbound_mail';
    case ForcePasswordReset = 'force_password_reset';
    case ContactCustomer = 'contact_customer';

    /**
     * Whether this platform can carry it out itself.
     *
     * Only suspension can: `TransitionService` exists and a case calls it
     * rather than writing `suspended` on a row, because two places that can
     * suspend a service is one too many.
     */
    public function isAutomatic(): bool
    {
        return $this === self::SuspendService;
    }

    public function labelKey(): string
    {
        return 'security.abuse.actions.'.$this->value;
    }
}
