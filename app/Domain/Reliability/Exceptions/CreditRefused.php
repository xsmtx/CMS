<?php

declare(strict_types=1);

namespace App\Domain\Reliability\Exceptions;

use App\Domain\Shared\Refused;

/**
 * A credit that will not be raised, and why.
 *
 * One constructor per reason, like `IncidentRefused` and `ChangeRefused`.
 * This one matters more than most: it moves money, so an audit log in which
 * "an operator tried to credit the same invoice twice" and "an operator tried
 * to credit somebody else's customer" look identical is an audit log that
 * cannot answer the only question anybody would ask it.
 */
final class CreditRefused extends Refused
{
    /**
     * A duration nobody knows yet is a figure nobody can agree. And the first
     * thing an operator would want afterwards is to change it, which is the
     * one thing a credit note cannot do.
     */
    public static function incidentIsOpen(string $reference): self
    {
        return new self(
            $reference.' is still open. An incident is credited once it has ended.',
            'reliability.credit_errors.incident_open',
            ['reference' => $reference],
        );
    }

    public static function alreadyCredited(string $number): self
    {
        return new self(
            $number.' has already been credited for this incident.',
            'reliability.credit_errors.already_credited',
            ['number' => $number],
        );
    }

    public static function differentSeller(): self
    {
        return new self(
            'That invoice belongs to a customer of a different seller.',
            'reliability.credit_errors.different_seller',
        );
    }

    public static function invoiceHasNoCustomer(string $number): self
    {
        return new self(
            $number.' has no customer to credit.',
            'reliability.credit_errors.no_customer',
            ['number' => $number],
        );
    }
}
