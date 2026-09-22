<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issuing an API token.
 *
 * The name is required and is the only thing that will ever identify the
 * token afterwards — the value itself is stored hashed. An expiry is
 * offered, and defaulting it to nothing is deliberate: a token that expires
 * silently in the middle of somebody's integration is worse than one they
 * chose to keep.
 */
final class ApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:96'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ];
    }
}
