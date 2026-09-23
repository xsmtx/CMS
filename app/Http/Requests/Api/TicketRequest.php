<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Support\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TicketRequest extends FormRequest
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
            'department_id' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
        ];
    }
}
