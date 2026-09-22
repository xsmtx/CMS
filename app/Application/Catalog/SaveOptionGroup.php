<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\OptionType;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a configurable option group, with its choices.
 *
 * The group and its options are saved together because they are one thing to
 * an operator: "control panel: none, cPanel, Plesk" is a single decision.
 * Saving them separately would leave a select with no choices on the
 * storefront if the second request never arrived.
 */
final readonly class SaveOptionGroup
{
    private const array AUDITED = [
        'name', 'key', 'type', 'is_required', 'min_quantity', 'max_quantity', 'position',
    ];

    public function __construct(private SavePriceMatrix $prices) {}

    public function handle(
        Product $product,
        OptionGroupAttributes $attributes,
        ?OptionGroup $group = null,
        ?Model $actor = null,
    ): OptionGroup {
        $creating = $group === null;
        $before = $creating ? [] : $group->only(self::AUDITED);

        $saved = DB::transaction(function () use ($product, $attributes, $group): OptionGroup {
            $values = [
                'name' => $attributes->name,
                'key' => $attributes->key,
                'type' => $attributes->type->value,
                'description' => $attributes->description,
                'is_required' => $attributes->isRequired,
                'min_quantity' => $attributes->minQuantity,
                'max_quantity' => $attributes->maxQuantity,
                'position' => $attributes->position,
            ];

            if ($group === null) {
                $group = $product->optionGroups()->create([
                    ...$values,
                    'organization_id' => $product->organization_id,
                ]);
            } else {
                $group->update($values);
            }

            $this->syncOptions($group, $attributes);

            return $group;
        });

        $audit = Audit::action($creating ? 'catalog.option_group.created' : 'catalog.option_group.updated')
            ->by($actor)
            ->on($saved)
            ->forOrganization($saved->organization_id);

        if (! $creating) {
            $audit->changed($before, $saved->only(self::AUDITED));
        }

        $audit->write();

        // Option prices are written after the group is committed, so a
        // pricing audit entry always refers to an option that exists.
        foreach ($saved->options()->get() as $option) {
            $submitted = $this->submittedFor($attributes, $option);

            if ($submitted !== null) {
                $this->prices->handle($option, $submitted->prices, $actor);
            }
        }

        return $saved->fresh(['options.prices']) ?? $saved;
    }

    private function syncOptions(OptionGroup $group, OptionGroupAttributes $attributes): void
    {
        $kept = [];
        $seenDefault = false;

        foreach ($attributes->options as $option) {
            // A choice is identified by its value within the group, which
            // is also the unique key: an edit that does not carry ids still
            // updates the existing rows instead of colliding with them.
            $record = $option->id === null
                ? $group->options()->where('value', $option->value)->first() ?? new Option
                : $group->options()->whereKey($option->id)->first() ?? new Option;

            // A select offers exactly one default; the first one wins rather
            // than the last, so the order on screen decides.
            $isDefault = $option->isDefault && ! $seenDefault;
            $seenDefault = $seenDefault || $isDefault;

            $record->fill([
                'organization_id' => $group->organization_id,
                'option_group_id' => $group->id,
                'label' => $option->label,
                'value' => $option->value,
                'is_default' => $attributes->type === OptionType::Quantity ? false : $isDefault,
                'position' => $option->position,
            ])->save();

            $kept[] = $record->getKey();
        }

        // Choices the operator removed go with their prices; the cascade on
        // option_prices takes care of the matrix.
        $group->options()->whereKeyNot($kept)->delete();
    }

    private function submittedFor(OptionGroupAttributes $attributes, Option $option): ?OptionAttributes
    {
        foreach ($attributes->options as $submitted) {
            if ($submitted->value === $option->value) {
                return $submitted;
            }
        }

        return null;
    }
}
