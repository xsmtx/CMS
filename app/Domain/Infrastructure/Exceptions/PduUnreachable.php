<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * A PDU, UPS or rack sensor that could not be read (§11).
 *
 * Its own type, like every other family's, because the sentence reaches an
 * operator and "the device pdu-r14-a did not answer" reads as a mistake in
 * the platform.
 *
 * **Throwing is the contract.** An empty answer means the PDU has no outlets,
 * which retires a rack's whole power path — so a PDU that is merely
 * unreachable must not be able to say it. A missing *probe* is different: a
 * 404 on a sensor collection is a PDU with nothing fitted, and the adapter
 * answers with nothing rather than throwing.
 */
final class PduUnreachable extends RuntimeException
{
    public static function noAnswer(string $source): self
    {
        return new self('The PDU '.$source.' did not answer.');
    }

    public static function refused(string $source): self
    {
        return new self('The PDU '.$source.' refused the credential.');
    }

    public static function answered(string $source, int $status): self
    {
        return new self('The PDU '.$source.' answered '.$status.'.');
    }

    public static function unreadable(string $source, string $what): self
    {
        return new self('The PDU '.$source.' answered something unreadable: '.$what.'.');
    }
}
