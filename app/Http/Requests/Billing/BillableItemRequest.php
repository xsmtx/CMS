<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording a one-off charge for the next invoice.
 *
 * **No currency field.** It is the customer's own, read by the controller: a
 * charge in a currency they are not billed in would wait for an invoice that
 * never comes, and there is no exchange rate in this product to rescue it.
 *
 * `unit_amount_minor` is **signed** — a negotiated reduction is a one-off
 * charge of a negative amount, and a credit note is the wrong document for
 * something that has not been invoiced yet.
 *
 * `exists` names the **table**, which is `services`.
 */
final class BillableItemRequest extends FormRequest
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
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_amount_minor' => ['required', 'integer'],
            'service' => ['nullable', 'string', 'exists:services,id'],
            // "Not before", never "on": the sweep decides when an invoice is
            // raised and this only says when the charge becomes eligible.
            'charge_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'description' => (string) __('billing.billables.description'),
            'quantity' => (string) __('billing.billables.quantity'),
            'unit_amount_minor' => (string) __('billing.billables.unit_price'),
            'charge_on' => (string) __('billing.billables.charge_on'),
        ];
    }
}
