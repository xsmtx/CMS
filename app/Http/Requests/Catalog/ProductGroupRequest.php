<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CatalogStatus;
use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductGroupRequest extends FormRequest
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
        $group = $this->route('group');
        $groupId = $group instanceof Model ? $group->getKey() : null;
        $organizationId = app(CurrentActor::class)->organizationId();

        return [
            'name' => ['required', 'string', 'max:191'],
            // Slugs appear in storefront URLs, so they are unique per
            // organization rather than globally: two resellers may both sell
            // a group called "shared-hosting".
            'slug' => [
                'nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('product_groups', 'slug')
                    ->where('organization_id', $organizationId)
                    ->ignore($groupId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
