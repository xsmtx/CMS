<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Catalog\Models\Product;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Delete a product, with its options, addons and price matrix.
 *
 * Deletion is for a product nobody ever bought. Once services exist
 * (Phase 3) this use case gains the check that refuses a product with
 * provisioned services attached; retiring it is the answer in that case,
 * which keeps the history intact and takes it off the storefront.
 */
final readonly class DeleteProduct
{
    public function handle(Product $product, ?Model $actor = null): void
    {
        Audit::action('catalog.product.deleted')
            ->by($actor)
            ->on($product)
            ->forOrganization($product->organization_id)
            ->write();

        $product->delete();
    }
}
