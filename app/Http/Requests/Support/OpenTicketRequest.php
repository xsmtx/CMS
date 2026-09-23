<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Domain\Support\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OpenTicketRequest extends FormRequest
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
            'department_id' => ['required', 'string', 'exists:support_departments,id'],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'service_id' => ['nullable', 'string', 'exists:services,id'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:'.(int) config('platform.support.attachments.max_kilobytes', 5120)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['department_id.required' => (string) __('support.errors.department_required')];
    }
}
