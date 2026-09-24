<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Where the cent goes.
 *
 * Not a detail: on an invoice with twelve lines the two answers differ by a few
 * cents, and which one a jurisdiction requires is a real difference rather than
 * a preference. Rounding per line is what most panels do; rounding once on the
 * total is what several tax authorities ask for.
 *
 * Whichever is chosen, the arithmetic stays integer minor units.
 */
enum TaxRounding: string
{
    case PerLine = 'per_line';
    case PerInvoice = 'per_invoice';

    public function labelKey(): string
    {
        return 'tax.rounding.'.$this->value;
    }
}
