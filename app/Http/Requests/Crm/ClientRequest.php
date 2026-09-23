<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Crm\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A whole client, as an operator types it.
 *
 * The password is optional on purpose: somebody taking details over the
 * telephone should be able to leave it blank and let the customer set their
 * own through the reset link. When it is given it meets the same rules a
 * customer's own password does — a support desk that can set a weaker one
 * has made the policy advisory.
 *
 * What the person may do is a **role**, not a row of checkboxes. This
 * platform grants capabilities through roles and reads them through the
 * permission registry; there is no `can_open_tickets` column and there
 * should not be one. The first person on an account owns it, which is what
 * the default says.
 */
final class ClientRequest extends FormRequest
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
            // The person.
            'first_name' => ['required', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            'email' => ['required', 'email', 'max:191', 'unique:contacts,email'],
            'phone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:12'],
            'password' => ['nullable', 'confirmed', Password::defaults()],

            // The company.
            'company_name' => ['nullable', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'tax_id_type' => ['nullable', 'string', 'max:24'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
            'currency_code' => ['required', 'string', 'size:3'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'send_overdue_notices' => ['sometimes', 'boolean'],
            'automatic_suspension' => ['sometimes', 'boolean'],
            'separate_invoices' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array', 'max:16'],
            'tag_ids.*' => ['string'],

            // Where to invoice them.
            'address_line_one' => ['nullable', 'string', 'max:191'],
            'address_line_two' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:96'],
            'region' => ['nullable', 'string', 'max:96'],
            'postal_code' => ['nullable', 'string', 'max:24'],
            // Required only once there is an address to put it on: a
            // country with no street is not an address.
            'country_code' => ['nullable', 'required_with:address_line_one', 'string', 'size:2'],

            'role' => ['sometimes', Rule::enum(SystemRole::class)],

            'notify_invoices' => ['sometimes', 'boolean'],
            'notify_support' => ['sometimes', 'boolean'],
            'notify_product' => ['sometimes', 'boolean'],
            'notify_marketing' => ['sometimes', 'boolean'],

            'custom_fields' => ['sometimes', 'array'],

            'send_welcome' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
