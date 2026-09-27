<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use App\Domain\Security\AbuseState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saying what has been done, and moving the state if it has moved.
 *
 * One request for both, the rule an incident's timeline states: moving a case
 * to `waiting_customer` without recording what the customer was told leaves
 * the next reader unable to answer "did we warn them".
 */
final class AbuseNoteRequest extends FormRequest
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
            'state' => ['required', Rule::enum(AbuseState::class)],
        ];
    }
}
