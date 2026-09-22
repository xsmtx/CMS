<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Catalog\Exceptions\InvalidPriceMatrix;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\Product;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Replace an item's price matrix.
 *
 * The matrix is saved whole rather than cell by cell: an operator editing a
 * price grid is making one decision about what the thing costs, and half of
 * it landing is worse than none of it.
 *
 * A cell absent from the submitted matrix is deleted, which is how "we no
 * longer sell this annually" is expressed. Nothing is converted from another
 * currency; an item is sold in a currency exactly when a row for it exists.
 */
final readonly class SavePriceMatrix
{
    /**
     * @param  list<PriceMatrixEntry>  $entries
     */
    public function handle(Product|Option|Addon $owner, array $entries, ?Model $actor = null): void
    {
        $this->assertNoDuplicateCells($entries);

        $owner->load('prices');
        $before = $this->snapshot($owner);

        DB::transaction(function () use ($owner, $entries): void {
            $kept = [];

            foreach ($entries as $entry) {
                $price = $owner->prices()->firstOrNew([
                    'billing_cycle' => $entry->cycle->value,
                    'currency_code' => $entry->currencyCode(),
                ]);

                $price->setAttribute('organization_id', $owner->getAttribute('organization_id'));
                $price->setAttribute('recurring', $entry->recurring);
                $price->setAttribute('setup', $entry->setup);
                $price->save();

                $kept[] = $price->getKey();
            }

            // An empty matrix removes every row, which is the honest way to
            // say the item is not for sale.
            $owner->prices()->whereKeyNot($kept)->delete();
        });

        $owner->load('prices');
        $after = $this->snapshot($owner);

        if ($before === $after) {
            return;
        }

        // Pricing gets its own audit action rather than riding along with
        // the product update: "who changed what this costs, and when" is the
        // question a billing dispute starts from.
        Audit::action('catalog.pricing.updated')
            ->by($actor)
            ->on($owner)
            ->forOrganization((string) $owner->getAttribute('organization_id'))
            ->changed($before, $after)
            ->write();
    }

    /**
     * @param  list<PriceMatrixEntry>  $entries
     */
    private function assertNoDuplicateCells(array $entries): void
    {
        $seen = [];

        foreach ($entries as $entry) {
            if (isset($seen[$entry->cellKey()])) {
                throw InvalidPriceMatrix::duplicateCell($entry->cycle, $entry->currencyCode());
            }

            $seen[$entry->cellKey()] = true;
        }
    }

    /**
     * The matrix as plain strings, so the audit diff reads the way the price
     * grid does.
     *
     * @return array<string, string>
     */
    private function snapshot(Product|Option|Addon $owner): array
    {
        $snapshot = [];

        foreach ($owner->prices as $price) {
            $cycle = $price->getAttribute('billing_cycle');
            $currency = $price->getAttribute('currency_code');

            $snapshot[$cycle->value.':'.$currency] = sprintf(
                '%s + %s setup',
                $price->getAttribute('recurring')?->toDecimalString() ?? '-',
                $price->getAttribute('setup')?->toDecimalString() ?? '-',
            );
        }

        ksort($snapshot);

        return $snapshot;
    }
}
