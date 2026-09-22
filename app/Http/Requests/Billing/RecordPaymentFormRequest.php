<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An operator saying money arrived.
 *
 * Amounts are minor units, as everywhere: the form multiplies out by the
 * currency's exponent so nothing is parsed from a decimal on this side.
 */
final class RecordPaymentFormRequest extends FormRequest
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
            'amount_minor' => ['required', 'integer', 'min:1'],
            'gateway' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:191'],
            'received_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:512'],
        ];
    }
}
