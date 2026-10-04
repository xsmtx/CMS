<?php

declare(strict_types=1);

namespace App\Http\Requests\Vendors;

use App\Domain\Vendors\ContractTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording what was agreed with a supplier (§24).
 *
 * The amount arrives as **minor units**, not as a decimal: money is integer
 * minor units and an ISO code everywhere in this product (non-negotiable 4),
 * and a float that reached a form would be a float that reached a column.
 *
 * `ends_on` is nullable on purpose and the null is a real answer — a rolling
 * agreement with no end date exists. `after_or_equal` rather than `after`,
 * because a one-day contract is somebody's weekend maintenance window and
 * refusing it would be this form having an opinion it has not earned.
 */
final class ContractRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],
            'term' => ['required', 'string', Rule::enum(ContractTerm::class)],
            'currency_code' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'auto_renews' => ['nullable', 'boolean'],
            // Bounded at a year: a notice period longer than the term it
            // sits in is a date arithmetic nobody meant.
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => (string) __('vendors.contracts.contract_title'),
            'term' => (string) __('vendors.contracts.term'),
            'amount_minor' => (string) __('vendors.contracts.amount'),
            'ends_on' => (string) __('vendors.contracts.ends_on'),
            'notice_days' => (string) __('vendors.contracts.notice_days'),
        ];
    }

    /**
     * The currency is upper-cased at the write, like every other writer of an
     * ISO code in this product.
     *
     * `CreateClient` learned this on a country: a row holding `usd` is a row
     * nothing else in the installation can group with, and the symptom is a
     * figure that quietly never adds up with the rest. A supplier's currency
     * is typed by hand because what a business *buys* in is not the list of
     * what it sells in — a seller invoicing in euros still pays a transit
     * provider in dollars.
     */
    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency_code');

        if (is_string($currency)) {
            $this->merge(['currency_code' => mb_strtoupper(trim($currency))]);
        }
    }
}
