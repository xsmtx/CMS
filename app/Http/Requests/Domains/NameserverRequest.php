<?php

declare(strict_types=1);

namespace App\Http\Requests\Domains;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where a customer wants their domain to point.
 *
 * At least two, because a registry rejects one and most refuse to say why
 * in a way a customer could act on.
 */
final class NameserverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'nameservers' => ['required', 'array', 'min:2', 'max:8'],
            'nameservers.*' => [
                'required',
                'string',
                'max:253',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i',
            ],
        ];
    }
}
