<?php

declare(strict_types=1);

namespace App\Http\Requests\Ordering;

use App\Support\Identity\CurrentActor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The checkout form.
 *
 * There is no password field, and there never will be: an account created
 * here is reached through the reset flow, which proves the address and
 * keeps a credential out of an order form.
 */
final class CheckoutRequest extends FormRequest
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
        // A signed-in customer has already given all of this.
        $guest = ! app(CurrentActor::class)->isClient();

        return [
            'terms' => ['accepted'],
            'expected_total' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'first_name' => [$guest ? 'required' : 'nullable', 'string', 'max:100'],
            'last_name' => [$guest ? 'required' : 'nullable', 'string', 'max:100'],
            'email' => [
                $guest ? 'required' : 'nullable', 'email:rfc', 'max:191',
                // Unique across contacts, because the address is what the
                // account is reached by.
                $guest ? 'unique:contacts,email' : 'nullable',
            ],
            'company' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'address_line' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => __('ordering.errors.terms_required'),
            'email.unique' => __('ordering.errors.email_taken'),
        ];
    }
}
