<?php

declare(strict_types=1);

namespace App\Http\Requests\Reliability;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Giving money back because of an outage.
 *
 * `amount_minor` because money is integer minor units everywhere in this
 * product — `MoneyInput` sends the integer and nothing divides by a hundred
 * in a browser.
 *
 * The reason is required and is not the incident's title. It is copied onto
 * the credit note, which is a numbered document the customer receives, so
 * "INC-000004" would be a sentence that means nothing to the person reading
 * it — the question it answers is *why is there money on my invoice*.
 */
final class SlaCreditRequest extends FormRequest
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
            'invoice' => ['required', 'string', 'exists:invoices,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
