<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * What kind of thing a device session was opened from.
 *
 * A closed list rather than the free string a client sends, because it is
 * drawn on a screen beside a Revoke button and "which of these is my phone"
 * is the question somebody is answering under pressure. `Other` is the honest
 * member for a client that did not say, and is never guessed into one of the
 * others from a user agent — a header a client controls is a header a client
 * can get wrong.
 */
enum DevicePlatform: string
{
    case Ios = 'ios';

    case Android = 'android';

    case Web = 'web';

    case Other = 'other';

    public function labelKey(): string
    {
        return 'identity.devices.platforms.'.$this->value;
    }

    /**
     * What a client sent, or `Other`.
     *
     * Deliberately not `from()`: a client sending nonsense must not be a 500,
     * and a device it is impossible to name is still a device somebody has to
     * be able to revoke.
     */
    public static function match(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Other;
    }
}
