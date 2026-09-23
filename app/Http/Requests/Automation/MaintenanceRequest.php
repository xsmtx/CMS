<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use Illuminate\Foundation\Http\FormRequest;

final class MaintenanceRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            // Read by a customer who cannot reach anything else, so it is
            // required when the switch goes on.
            'message' => ['nullable', 'required_if:enabled,true', 'string', 'max:500'],
            'until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
