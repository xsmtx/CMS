<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * What a rule is charged on.
 *
 * Four members because four things are billed here and a jurisdiction may treat
 * them differently: several countries tax a domain registration and a hosting
 * account at different rates, or exempt one of them.
 *
 * `Manual` is the escape hatch for a line an operator typed onto an invoice
 * themselves, which is neither a product nor a domain and would otherwise fall
 * through every rule.
 */
enum TaxAppliesTo: string
{
    case All = 'all';
    case Products = 'products';
    case Domains = 'domains';
    case Addons = 'addons';
    case Manual = 'manual';

    public function covers(self $subject): bool
    {
        return $this === self::All || $this === $subject;
    }

    public function labelKey(): string
    {
        return 'tax.applies_to.'.$this->value;
    }
}
