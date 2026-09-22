<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Product;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Create or update an addon.
 *
 * An addon is a separate line a customer can add or drop later, so it
 * carries its own price matrix rather than a delta on the product's.
 */
final readonly class SaveAddon
{
    private const array AUDITED = ['name', 'slug', 'status', 'position'];

    public function handle(
        Product $product,
        AddonAttributes $attributes,
        ?Addon $addon = null,
        ?Model $actor = null,
    ): Addon {
        $creating = $addon === null;
        $before = $creating ? [] : $addon->only(self::AUDITED);

        $values = [
            'name' => $attributes->name,
            'slug' => $attributes->slug ?? '',
            'description' => $attributes->description,
            'status' => $attributes->status->value,
            'position' => $attributes->position,
        ];

        if ($addon === null) {
            $addon = $product->addons()->create([
                ...$values,
                'organization_id' => $product->organization_id,
            ]);
        } else {
            $addon->update($values);
        }

        $audit = Audit::action($creating ? 'catalog.addon.created' : 'catalog.addon.updated')
            ->by($actor)
            ->on($addon)
            ->forOrganization($addon->organization_id);

        if (! $creating) {
            $audit->changed($before, $addon->only(self::AUDITED));
        }

        $audit->write();

        return $addon;
    }
}
