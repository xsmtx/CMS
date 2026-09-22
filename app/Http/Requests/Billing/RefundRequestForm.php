<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sending money back.
 *
 * The reason is required, unlike on a payment: money leaving the business
 * is the movement someone asks about afterwards.
 */
final class RefundRequestForm extends FormRequest
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
