<?php

declare(strict_types=1);

namespace App\Http\Requests\Vendors;

use App\Domain\Vendors\VendorKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or changing a supplier (§24).
 *
 * `authorize()` is true because the controller asks `vendors.manage` before
 * anything is read — the rule Phase 17 learned on the Licence screen: with
 * the check inside this class, somebody who may not touch the screen fills in
 * a form and is refused afterwards.
 */
final class VendorRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'string', Rule::enum(VendorKind::class)],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            // A string rather than a phone rule: core ships no country's
            // numbering plan, and refusing a number somebody can dial would
            // be worse than storing one nobody validated.
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'account_reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => (string) __('vendors.name'),
            'kind' => (string) __('vendors.kind'),
            'contact_email' => (string) __('vendors.contact_email'),
        ];
    }
}
