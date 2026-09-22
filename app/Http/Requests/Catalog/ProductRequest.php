<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\ProductType;
use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Model ? $product->getKey() : null;
        $organizationId = app(CurrentActor::class)->organizationId();

        return [
            'product_group_id' => ['required', 'string', 'ulid'],
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')
                    ->where('organization_id', $organizationId)
                    ->ignore($productId),
            ],
            'type' => ['required', Rule::enum(ProductType::class)],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'features' => ['sometimes', 'array', 'max:32'],
            // A blank line arrives as null, not as an empty string: the
            // request middleware converts it. Rejecting it would make the
            // textarea unusable.
            'features.*' => ['nullable', 'string', 'max:191'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            // Null is unlimited and zero is sold out, so the field is
            // nullable rather than defaulted.
            'stock' => ['nullable', 'integer', 'min:0'],
            'requires_domain' => ['nullable', 'boolean'],
        ];
    }
}
