<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

final class NotificationPreferenceRequest extends FormRequest
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
            'invoices' => ['sometimes', 'boolean'],
            'support' => ['sometimes', 'boolean'],
            'product' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
        ];
    }
}
