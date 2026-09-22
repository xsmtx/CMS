<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CreditRequest extends FormRequest
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
            // Credit is money. Granting it without saying why leaves the
            // next person to read the ledger guessing.
            'reason' => ['required', 'string', 'min:3', 'max:512'],
        ];
    }
}
