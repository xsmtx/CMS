<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What a piece of evidence is (§13).
 *
 * **Core keeps a reference, never the thing.** A complaint contains a third
 * party's data — headers, addresses, sometimes a message body — and hosting
 * that forever in a table nobody sized is the wrong shape twice over: a
 * privacy problem and a storage one. So each of these names something small
 * and bounded: an id, a URL, a hash, an excerpt.
 */
enum EvidenceKind: string
{
    case Url = 'url';
    case IpAddress = 'ip_address';
    case Domain = 'domain';
    case MailMessageId = 'mail_message_id';
    case LogExcerpt = 'log_excerpt';
    case FileHash = 'file_hash';
    case ComplaintReference = 'complaint_reference';
    case Note = 'note';

    public function labelKey(): string
    {
        return 'security.abuse.evidence_kinds.'.$this->value;
    }
}
