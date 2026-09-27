<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Where an abuse case has got to (§13).
 *
 * **It never ends in "resolved"**, and that is the difference from an
 * incident. An incident is the platform's fault and ends when the platform is
 * working again; a case ends in a *decision* — something was done, nothing
 * needed doing, or the complaint was wrong. Saying "resolved" would flatten
 * the three into a word that tells the next reader nothing.
 *
 * - `open` — it arrived and nobody has looked.
 * - `investigating` — somebody is looking, and the customer may not know yet.
 * - `waiting_customer` — the customer has been told and has time to act. The
 *   one state where the clock is theirs rather than the desk's.
 * - `actioned` — something was done to the account.
 * - `no_action` — real, and deliberately not a failure: "the customer fixed
 *   it before we acted" and "it was one login attempt" both end here.
 * - `rejected` — the complaint was wrong, or was about somebody else. Kept,
 *   because a sender who is wrong twice is a fact worth having.
 */
enum AbuseState: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case WaitingCustomer = 'waiting_customer';
    case Actioned = 'actioned';
    case NoAction = 'no_action';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Open, self::Investigating, self::WaitingCustomer => true,
            self::Actioned, self::NoAction, self::Rejected => false,
        };
    }

    /**
     * The tone `AppStatus` draws it in, decided here rather than in a Vue
     * file — one mapping, on the server.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'critical',
            self::Investigating => 'warning',
            self::WaitingCustomer => 'info',
            self::Actioned => 'healthy',
            self::NoAction, self::Rejected => 'neutral',
        };
    }

    public function labelKey(): string
    {
        return 'security.abuse.states.'.$this->value;
    }
}
