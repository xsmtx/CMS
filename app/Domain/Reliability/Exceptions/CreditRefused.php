<?php

declare(strict_types=1);

namespace App\Domain\Reliability\Exceptions;

use RuntimeException;

/**
 * A credit that will not be raised, and why.
 *
 * One constructor per reason, like `IncidentRefused` and `ChangeRefused`.
 * This one matters more than most: it moves money, so an audit log in which
 * "an operator tried to credit the same invoice twice" and "an operator tried
 * to credit somebody else's customer" look identical is an audit log that
 * cannot answer the only question anybody would ask it.
 */
final class CreditRefused extends RuntimeException
{
    /**
     * A duration nobody knows yet is a figure nobody can agree. And the first
     * thing an operator would want afterwards is to change it, which is the
     * one thing a credit note cannot do.
     */
    public static function incidentIsOpen(string $reference): self
    {
        return new self($reference.' is still open. An incident is credited once it has ended.');
    }

    public static function alreadyCredited(string $number): self
    {
        return new self($number.' has already been credited for this incident.');
    }

    public static function differentSeller(): self
    {
        return new self('That invoice belongs to a customer of a different seller.');
    }

    public static function invoiceHasNoCustomer(string $number): self
    {
        return new self($number.' has no customer to credit.');
    }
}
