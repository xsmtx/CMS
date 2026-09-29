<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Staff;

use App\Domain\Reliability\IncidentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saying something about an incident, from a phone.
 *
 * `authorize()` is true because the scope middleware has already asked both
 * questions — does the token carry `incidents:write`, and does its holder
 * hold `reliability.incidents.manage` — before this class is reached. A third
 * check here would be a third place to keep in step.
 *
 * `state` is absent on the resolve route and required on the update one. It
 * is the same class because the body is the same body, and the rule reads the
 * route rather than being two classes that would drift.
 */
final class IncidentUpdateRequest extends FormRequest
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
            // The sentence is the point of the act. An update with an empty
            // body moves a state and tells nobody anything, which is the
            // move that makes a status page useless.
            'body' => ['required', 'string', 'min:3', 'max:4000'],
            'public' => ['nullable', 'boolean'],
            'state' => [
                $this->resolving() ? 'nullable' : 'required',
                'string',
                /*
                 * `resolved` is deliberately not offered here. It has a
                 * figure to freeze and a timestamp to write, and a second
                 * way to reach it would be the one that forgot.
                 */
                Rule::in(array_values(array_diff(
                    array_column(IncidentState::cases(), 'value'),
                    [IncidentState::Resolved->value],
                ))),
            ],
        ];
    }

    private function resolving(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.resolve');
    }
}
