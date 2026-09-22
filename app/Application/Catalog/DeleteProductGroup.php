<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Catalog\Exceptions\GroupNotEmpty;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Delete an empty product group.
 *
 * A group holding products is refused rather than cascaded: retiring it
 * hides it from the storefront without destroying what customers bought.
 */
final readonly class DeleteProductGroup
{
    public function handle(ProductGroup $group, ?Model $actor = null): void
    {
        $products = $group->products()->count();

        if ($products > 0) {
            throw GroupNotEmpty::holding($products);
        }

        // Written before the delete, so the entry still has a record to
        // describe.
        Audit::action('catalog.group.deleted')
            ->by($actor)
            ->on($group)
            ->forOrganization($group->organization_id)
            ->write();

        $group->delete();
    }
}
