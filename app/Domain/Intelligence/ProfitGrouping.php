<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Which question the profitability report is answering (§21).
 *
 * Three, because they are three different conversations. By customer is the
 * one a commercial manager opens; by product is the one that decides what to
 * stop selling; by server is the one that decides what to stop buying.
 *
 * There is deliberately no grouping by *month over time*. A cost entry is a
 * statement that is edited when the price changes, not an append-only
 * history, so a chart of margin over twelve months would redraw itself every
 * time somebody corrected a figure — and a trend that changes when you fix a
 * typo is a trend nobody can use.
 */
enum ProfitGrouping: string
{
    case Customer = 'customer';

    case Product = 'product';

    case Server = 'server';

    public function labelKey(): string
    {
        return 'intelligence.profit.groupings.'.$this->value;
    }
}
