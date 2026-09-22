<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A correction to an issued invoice.
 *
 * The reason goes on the document itself, so it has to read as an
 * explanation rather than a note to self.
 */
final class CreditNoteRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:512'],
        ];
    }
}
