<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CatalogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddonRequest extends FormRequest
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
        $addon = $this->route('addon');
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('addons', 'slug')
                    ->where('product_id', $product instanceof Model ? $product->getKey() : null)
                    ->ignore($addon instanceof Model ? $addon->getKey() : null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
