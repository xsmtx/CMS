<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class TicketReplyRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:20000'],
            'internal' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            // Size and type are checked again in `StoreAttachment`, against
            // the configured allow-lists. This is the cheap first pass.
            'attachments.*' => ['file', 'max:'.(int) config('platform.support.attachments.max_kilobytes', 5120)],
        ];
    }
}
