<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Access\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RoleRequest extends FormRequest
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
        // The route parameter is a bound model on update and absent on
        // create; either way the unique rule needs the key, not the object.
        $current = $this->route('role');
        $ignore = $current instanceof Model ? $current->getKey() : $current;

        return [
            'name' => ['required', 'string', 'max:128'],
            'slug' => [
                'required',
                'string',
                'max:64',
                // Slugs appear in policies and in module manifests, so the
                // shape is constrained rather than merely unique.
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('roles', 'slug')->ignore($ignore),
            ],
            'scope' => ['required', Rule::enum(RoleScope::class)],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_slugs' => ['sometimes', 'array'],
            'permission_slugs.*' => ['string', 'max:191'],
        ];
    }
}
