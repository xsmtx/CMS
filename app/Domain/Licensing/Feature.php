<?php

declare(strict_types=1);

namespace App\Domain\Licensing;

/**
 * The commercial features this platform knows how to gate.
 *
 * A closed list rather than free strings, so that a typo fails loudly
 * rather than silently denying something — and so the whole set is
 * readable in one file, which is the only honest way to answer "what does
 * a licence actually change".
 *
 * Exactly one member today. It will grow when there is something else that
 * genuinely differs, not in anticipation of one.
 */
enum Feature: string
{
    case RemoveVendorMark = 'branding.remove_vendor_mark';

    public function labelKey(): string
    {
        return 'branding.features.'.str_replace('.', '_', $this->value);
    }
}
