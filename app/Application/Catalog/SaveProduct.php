<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Catalog\Exceptions\GroupOutsideBoundary;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Create or update a product.
 *
 * Prices are deliberately not part of this: they are their own use case with
 * their own permission and their own audit action, because changing what
 * something costs is a different decision from changing how it is described.
 */
final readonly class SaveProduct
{
    private const array AUDITED = [
        'product_group_id', 'name', 'slug', 'type', 'tagline',
        'status', 'position', 'stock', 'requires_domain',
    ];

    public function handle(
        string $organizationId,
        ProductAttributes $attributes,
        ?Product $product = null,
        ?Model $actor = null,
    ): Product {
        $group = $this->resolveGroup($organizationId, $attributes->productGroupId);

        $creating = $product === null;
        $before = $creating ? [] : $product->only(self::AUDITED);

        $values = [
            'product_group_id' => $group->id,
            'name' => $attributes->name,
            'slug' => $attributes->slug ?? '',
            'type' => $attributes->type->value,
            'tagline' => $attributes->tagline,
            'description' => $attributes->description,
            'features' => $attributes->features,
            'status' => $attributes->status->value,
            'position' => $attributes->position,
            'stock' => $attributes->stock,
        ];

        // Left out entirely when the operator did not choose, so the model
        // can derive it from the product type.
        if ($attributes->requiresDomain !== null) {
            $values['requires_domain'] = $attributes->requiresDomain;
        }

        if ($product === null) {
            $product = Product::query()->create([...$values, 'organization_id' => $organizationId]);
        } else {
            $product->update($values);
        }

        $audit = Audit::action($creating ? 'catalog.product.created' : 'catalog.product.updated')
            ->by($actor)
            ->on($product)
            ->forOrganization($product->organization_id);

        if (! $creating) {
            $audit->changed($before, $product->only(self::AUDITED));
        }

        $audit->write();

        return $product;
    }

    /**
     * A product may only be filed under a group its own organization owns.
     *
     * The global scope already hides other organizations' groups, so this
     * turns a silent miss into a stated refusal.
     */
    private function resolveGroup(string $organizationId, string $groupId): ProductGroup
    {
        $group = ProductGroup::query()->whereKey($groupId)->first();

        if ($group === null || $group->organization_id !== $organizationId) {
            throw GroupOutsideBoundary::forId($groupId);
        }

        return $group;
    }
}
