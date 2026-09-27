<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What somebody is complaining about (§13).
 *
 * An open-ish list of the things an abuse desk actually receives, and
 * deliberately not a free string: a case's kind decides which evidence is
 * worth keeping and which guarded action is even plausible, and a typo would
 * produce a case nobody can find twice.
 *
 * `Other` exists because an abuse desk receives things nobody anticipated,
 * and a desk that could not record one would record it in a ticket instead —
 * where it has no retention clock and no correlation.
 */
enum AbuseKind: string
{
    case Phishing = 'phishing';
    case Malware = 'malware';
    case Spam = 'spam';
    case BruteForce = 'brute_force';
    case Compromise = 'compromise';
    case Vulnerability = 'vulnerability';
    case Blocklist = 'blocklist';
    case Copyright = 'copyright';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'security.abuse.kinds.'.$this->value;
    }
}
