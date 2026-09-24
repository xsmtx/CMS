<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Http\Requests\Concerns\AsksForATaxId;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The party the next invoice will be made out to.
 *
 * The address is required in full once it is being edited at all: a
 * half-filled billing address produces a document an accountant will send
 * back. The tax id is not validated against a country's format here — that
 * is the tax contract's job, and core never learns a jurisdiction's rules.
 */
final class BillingDetailsRequest extends FormRequest
{
    use AsksForATaxId;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->taxIdMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'tax_id' => $this->taxIdRules(),

            'line_one' => ['required', 'string', 'max:191'],
            'line_two' => ['nullable', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:96'],
            'region' => ['nullable', 'string', 'max:96'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
        ];
    }
}
