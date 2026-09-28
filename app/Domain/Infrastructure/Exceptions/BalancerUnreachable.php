<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * A load balancer that could not be read or written (§9).
 *
 * Its own type rather than `DeviceUnreachable` or `StorageUnreachable`, for
 * the reason each of those has one: the sentence reaches an operator, and
 * "the device haproxy did not answer" reads as a mistake in the platform.
 *
 * **Throwing is the contract.** `LoadBalancerProvider` says why: an empty
 * answer is taken literally by the sweep — every listener this balancer had
 * has gone — so a balancer that is merely unreachable must not be able to
 * return one.
 *
 * On the write side it is caught by `DrainBackend`, wrapped in a
 * `BackendRefused` whose message names *which* refusal it was, and recorded
 * on the audit row through `SecretRedactor`.
 */
final class BalancerUnreachable extends RuntimeException
{
    public static function noAnswer(string $source): self
    {
        return new self('The load balancer '.$source.' did not answer.');
    }

    public static function refused(string $source): self
    {
        return new self('The load balancer '.$source.' refused the credential.');
    }

    public static function answered(string $source, int $status): self
    {
        return new self('The load balancer '.$source.' answered '.$status.'.');
    }

    public static function unreadable(string $source, string $what): self
    {
        return new self('The load balancer '.$source.' answered something unreadable: '.$what.'.');
    }
}
