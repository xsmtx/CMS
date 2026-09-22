<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Catalog\Exceptions\CurrencyInUse;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Remove a currency an installation no longer trades in.
 *
 * Refused while prices are still quoted in it, and refused for the base
 * currency: everything else is quoted against the base, so removing it would
 * leave the remaining rates meaningless. Deactivating is the way to stop
 * selling in a currency without destroying the prices.
 */
final readonly class DeleteCurrency
{
    public function handle(CurrencyRecord $record, ?Model $actor = null): void
    {
        if ($record->is_base) {
            throw CurrencyInUse::asBase($record->code);
        }

        $prices = ProductPrice::query()->where('currency_code', $record->code)->count();

        if ($prices > 0) {
            throw CurrencyInUse::forPrices($record->code, $prices);
        }

        Audit::action('catalog.currency.deleted')
            ->by($actor)
            ->on($record)
            ->forOrganization($record->organization_id)
            ->write();

        $record->delete();
    }
}
