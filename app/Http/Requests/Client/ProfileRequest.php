<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's own profile form.
 *
 * Deliberately narrow: a customer can correct their name, phone and
 * notification preferences, and the account owner can correct the company
 * details. Status, currency and tags are the provider's to set.
 */
final class ProfileRequest extends FormRequest
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
            'first_name' => ['sometimes', 'required', 'string', 'max:96'],
            'last_name' => ['sometimes', 'required', 'string', 'max:96'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notify_invoices' => ['sometimes', 'boolean'],
            'notify_support' => ['sometimes', 'boolean'],
            'notify_product' => ['sometimes', 'boolean'],
            'notify_marketing' => ['sometimes', 'boolean'],

            'company_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ];
    }
}
