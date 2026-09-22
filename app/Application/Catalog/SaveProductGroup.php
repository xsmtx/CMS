<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Create or update a product group.
 *
 * One use case for both: the form is the same, and the only difference is
 * whether a record already exists.
 */
final readonly class SaveProductGroup
{
    private const array AUDITED = ['name', 'slug', 'description', 'status', 'position'];

    public function handle(
        string $organizationId,
        ProductGroupAttributes $attributes,
        ?ProductGroup $group = null,
        ?Model $actor = null,
    ): ProductGroup {
        $creating = $group === null;
        $before = $creating ? [] : $group->only(self::AUDITED);

        $values = [
            'name' => $attributes->name,
            // An empty slug asks the model to derive one from the name.
            'slug' => $attributes->slug ?? '',
            'description' => $attributes->description,
            'status' => $attributes->status->value,
            'position' => $attributes->position,
        ];

        if ($group === null) {
            $group = ProductGroup::query()->create([...$values, 'organization_id' => $organizationId]);
        } else {
            $group->update($values);
        }

        $audit = Audit::action($creating ? 'catalog.group.created' : 'catalog.group.updated')
            ->by($actor)
            ->on($group)
            ->forOrganization($group->organization_id);

        if (! $creating) {
            $audit->changed($before, $group->only(self::AUDITED));
        }

        $audit->write();

        return $group;
    }
}
