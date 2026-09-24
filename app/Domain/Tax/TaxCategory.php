<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Ordering\LineKind;

/**
 * Which tax treatment a billed line falls under.
 *
 * The one place the two vocabularies meet. `LineKind` is ordering's word for what
 * a line *is*; `TaxAppliesTo` is tax's word for what a rule is charged *on*, and
 * it has a member ordering has no equivalent for — `Manual`, the line an operator
 * typed onto an invoice themselves.
 *
 * A `match` with no default, deliberately. A fifth kind of line would fail to
 * compile here rather than silently landing in whichever category came first,
 * which on a tax screen means whichever rate came first.
 */
final readonly class TaxCategory
{
    public static function of(LineKind $kind): TaxAppliesTo
    {
        return match ($kind) {
            LineKind::Product => TaxAppliesTo::Products,
            LineKind::Addon => TaxAppliesTo::Addons,
            LineKind::Domain => TaxAppliesTo::Domains,
        };
    }
}
