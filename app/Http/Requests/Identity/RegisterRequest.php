<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\Concerns\AsksForATaxId;
use App\Support\Catalog\StorefrontCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A visitor opening their own account.
 *
 * Deliberately the same rules an operator's client form uses, field for
 * field, because the two produce the same rows: a customer, a primary
 * contact with portal access, and optionally an address to invoice. A
 * registration form with looser rules than the admin form would be the
 * cheaper way in, and the one everybody uses.
 *
 * Unlike checkout there **is** a password field, and that is the whole
 * difference between the two paths: somebody who has bought something gets
 * an account they claim through the reset flow (which proves the address),
 * and somebody who has bought nothing gets one they chose a password for.
 * Neither ever receives a password this platform generated.
 *
 * The currency is validated against the currencies this installation
 * actually trades in, not against a list in the browser: it is written onto
 * the customer and every price they are ever quoted follows from it.
 */
final class RegisterRequest extends FormRequest
{
    use AsksForATaxId;

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
            'first_name' => ['required', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            // Unique across contacts, because the address is what the
            // account is reached by. It tells a registering visitor that the
            // address is taken, which is unavoidable: a form that refused to
            // say so would refuse to let them register at all.
            'email' => ['required', 'string', 'email:filter', 'max:191', 'unique:contacts,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:64'],

            'company_name' => ['nullable', 'string', 'max:191'],
            // A business is a company name, and the seller decides whether
            // that obliges a tax id (`Customer::isBusiness()`).
            'tax_id' => $this->taxIdRules('company_name'),
            'currency_code' => ['required', 'string', 'size:3', Rule::in($this->currencies())],

            'address_line_one' => ['nullable', 'string', 'max:191'],
            'address_line_two' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:96'],
            'region' => ['nullable', 'string', 'max:96'],
            'postal_code' => ['nullable', 'string', 'max:24'],
            // Required only once there is an address to put it on: a country
            // with no street is not an address.
            'country_code' => ['nullable', 'required_with:address_line_one', 'string', 'size:2', 'alpha'],

            'marketing_opt_in' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => (string) __('identity.register.email_taken'),
            ...$this->taxIdMessages(),
        ];
    }

    /**
     * @return list<string>
     */
    private function currencies(): array
    {
        $available = app(StorefrontCurrency::class)->available();

        // An installation with no currency rows would otherwise refuse every
        // registration with a message about a field the visitor cannot see.
        return $available === []
            ? [(string) config('platform.crm.default_currency', 'TRY')]
            : $available;
    }
}
