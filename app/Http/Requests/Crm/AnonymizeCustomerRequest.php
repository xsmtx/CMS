<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

final class AnonymizeCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Irreversible, so the reason is required and recorded. A stated
            // intent is the only thing that makes the action reviewable
            // afterwards.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmation' => ['required', 'accepted'],
        ];
    }
}
