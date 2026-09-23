<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Domain\Domains\DomainAction;
use App\Infrastructure\Domains\Models\Tld;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Saves an extension and its whole price matrix in one transaction.
 *
 * Whole, not cell by cell, for the same reason the product price grid is
 * saved whole: an operator who removes a currency expects it to be gone,
 * and a partial save leaves a matrix nobody can reason about.
 */
final readonly class SaveTld
{
    public function __construct(private TldCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{action: string, years: int, currency_code: string, amount_minor: int, cost_minor?: int|null}>  $prices
     */
    public function handle(?Tld $tld, array $attributes, array $prices, ?Model $actor = null): Tld
    {
        $before = $tld?->only(['extension', 'registrar', 'status']);

        $saved = DB::transaction(function () use ($tld, $attributes, $prices): Tld {
            $record = $tld ?? new Tld;

            $record->fill([
                ...$attributes,
                'extension' => mb_strtolower(ltrim((string) $attributes['extension'], '.')),
            ]);
            $record->save();

            // Replaced whole. A cell that is gone from the payload is a
            // term the operator has stopped selling.
            $record->prices()->delete();

            foreach ($prices as $price) {
                $record->prices()->create([
                    'organization_id' => $record->organization_id,
                    'action' => DomainAction::from($price['action'])->value,
                    'years' => $price['years'],
                    'currency_code' => mb_strtoupper($price['currency_code']),
                    'amount_minor' => $price['amount_minor'],
                    'cost_minor' => $price['cost_minor'] ?? null,
                ]);
            }

            return $record;
        });

        // The catalog memoises what is on sale; it has just changed.
        $this->catalog->forget();

        Audit::action($tld === null ? 'domains.tld.created' : 'domains.tld.updated')
            ->by($actor)
            ->on($saved)
            ->forOrganization($saved->organization_id)
            ->changed($before ?? [], $saved->only(['extension', 'registrar', 'status']))
            ->withMetadata(['prices' => count($prices)])
            ->write();

        return $saved;
    }
}
