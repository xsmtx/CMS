<?php

declare(strict_types=1);

namespace App\Http\Requests\Network;

use App\Domain\Network\GrantableCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Giving somebody a capability for a window (§17).
 *
 * `minutes` rather than a date, because the question an operator is answering
 * is "for how long" and a date picker makes them do arithmetic to answer it.
 * The bounds are checked again in `AccessGrants` against the installation's
 * own maximum — a form is a convenience and never the rule.
 *
 * `exists` names the **table**, which is `staff_users`: the mistake this
 * product has found twice on `departments` is worth writing out each time.
 */
final class GrantAccessRequest extends FormRequest
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
            'staff' => ['required', 'string', 'exists:staff_users,id'],
            'capability' => ['required', Rule::enum(GrantableCapability::class)],
            'minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'reason' => ['required', 'string', 'max:2000'],
            'ticket' => ['nullable', 'string', 'max:64'],
        ];
    }
}
