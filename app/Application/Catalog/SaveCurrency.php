<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Shared\Currency;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Shared\Models\ExchangeRateSnapshot;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Add a currency to trade in, or change its rate.
 *
 * Every rate the operator sets is also appended to the snapshot table. The
 * `currencies` row answers "what is the rate now"; the snapshots answer
 * "what was it in March", which is the question a reissued document asks.
 */
final readonly class SaveCurrency
{
    private const array AUDITED = ['code', 'name', 'symbol', 'rate', 'is_base', 'is_active'];

    public function handle(
        string $organizationId,
        CurrencyAttributes $attributes,
        ?CurrencyRecord $record = null,
        ?Model $actor = null,
    ): CurrencyRecord {
        // Resolving the ISO definition first turns a typo into a refusal
        // here rather than a half-written row later.
        $currency = Currency::of($attributes->code);

        $creating = $record === null;
        $before = $creating ? [] : $record->only(self::AUDITED);

        $saved = DB::transaction(function () use ($organizationId, $attributes, $currency, $record): CurrencyRecord {
            $values = [
                'code' => $currency->code,
                'name' => $attributes->name,
                'symbol' => $attributes->symbol,
                'exponent' => $currency->exponent,
                'rate' => $attributes->rate,
                'is_base' => $attributes->isBase,
                'is_active' => $attributes->isActive,
            ];

            if ($record === null) {
                $record = CurrencyRecord::query()->create([...$values, 'organization_id' => $organizationId]);
            } else {
                $record->update($values);
            }

            // The model forces a base currency to a rate of one, so the
            // snapshot is taken from the saved row rather than the input.
            ExchangeRateSnapshot::query()->create([
                'organization_id' => $record->organization_id,
                'currency_id' => $record->id,
                'code' => $record->code,
                'rate' => $record->rate,
                'source' => $attributes->rateSource ?? 'manual',
                'captured_at' => now(),
            ]);

            return $record;
        });

        $audit = Audit::action($creating ? 'catalog.currency.created' : 'catalog.currency.updated')
            ->by($actor)
            ->on($saved)
            ->forOrganization($saved->organization_id);

        if (! $creating) {
            $audit->changed($before, $saved->only(self::AUDITED));
        }

        $audit->write();

        return $saved;
    }
}
