<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer adding or editing one of their own contacts.
 *
 * Narrower than the staff form on purpose: status and primacy are the
 * provider's to set, so they are absent here rather than merely ignored.
 */
final class ClientContactRequest extends FormRequest
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
        $current = $this->route('contact');
        $ignore = $current instanceof Model ? $current->getKey() : $current;

        return [
            'first_name' => ['required', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:191',
                Rule::unique('contacts', 'email')->ignore($ignore),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'portal_access' => ['sometimes', 'boolean'],
            'notify_invoices' => ['sometimes', 'boolean'],
            'notify_support' => ['sometimes', 'boolean'],
            'notify_product' => ['sometimes', 'boolean'],
            'notify_marketing' => ['sometimes', 'boolean'],
        ];
    }
}
