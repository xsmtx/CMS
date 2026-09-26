<?php

declare(strict_types=1);

namespace App\Http\Requests\Network;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yes, no, or the requester taking it back.
 *
 * One request for the three, because they are the same form with a different
 * verb — and three request classes would be three places the note's length
 * was decided.
 */
final class DecideNetworkChangeRequest extends FormRequest
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
            'decision' => ['required', Rule::in(['approve', 'reject', 'cancel'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
