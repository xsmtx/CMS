<?php

declare(strict_types=1);

namespace App\Http\Requests\Vendors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a quantity of operational licences (§24).
 *
 * `for_module` names the provisioning module a machine running this licence
 * would be configured with, and it is **nullable on purpose**: core has no
 * way to know which machines an Imunify licence belongs on unless somebody
 * says, and a pool that says nothing produces no gap rather than a guess.
 *
 * It is validated against the modules this installation actually has, so a
 * pool cannot point at a module that was uninstalled — the list a control is
 * drawn from and the list a write is validated against must be the same list.
 */
final class LicencePoolRequest extends FormRequest
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
            'vendor_id' => ['required', 'string', Rule::exists('vendors', 'id')],
            'contract_id' => ['nullable', 'string', Rule::exists('contracts', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'for_module' => ['nullable', 'string', 'max:64'],
            // Bounded well above anything plausible rather than at a number
            // somebody would hit: a hoster with nine thousand cPanel seats
            // exists, and a limit they met would be this form refusing the
            // truth.
            'seats' => ['required', 'integer', 'min:0', 'max:1000000'],
            'currency_code' => ['required', 'string', 'size:3'],
            'unit_amount_minor' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vendor_id' => (string) __('vendors.licences.vendor'),
            'name' => (string) __('vendors.licences.name'),
            'seats' => (string) __('vendors.licences.seats'),
            'unit_amount_minor' => (string) __('vendors.licences.unit_price'),
        ];
    }

    /**
     * The currency is upper-cased at the write, like every other writer of an
     * ISO code here. A row holding `usd` groups with nothing.
     */
    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency_code');

        if (is_string($currency)) {
            $this->merge(['currency_code' => mb_strtoupper(trim($currency))]);
        }

        // An empty select posts an empty string, and a nullable column wants
        // a null. The ticket screen wrote `''` into a ULID column for want of
        // exactly this.
        foreach (['contract_id', 'for_module'] as $optional) {
            if ($this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }
    }
}
