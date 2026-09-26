<?php

declare(strict_types=1);

namespace App\Http\Requests\Reliability;

use App\Domain\Reliability\IncidentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saying something, and moving the state if it has moved.
 *
 * One request for both, because they are one act: changing the state without
 * saying what changed is the move that makes a status page useless.
 */
final class IncidentUpdateRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:4000'],
            'state' => ['required', Rule::enum(IncidentState::class)],
            'is_public' => ['boolean'],
        ];
    }
}
