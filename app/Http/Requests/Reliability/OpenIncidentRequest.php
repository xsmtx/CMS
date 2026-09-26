<?php

declare(strict_types=1);

namespace App\Http\Requests\Reliability;

use App\Domain\Reliability\AlertSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening an incident.
 *
 * `body` is required, which is the rule worth enforcing here: an incident
 * with a title and nothing else is an incident whose timeline begins with a
 * gap somebody has to explain afterwards, and a postmortem is written from
 * the timeline.
 *
 * `started_at` is optional and is **not** "now": it is when the customer's
 * world broke, which an operator usually learns twenty minutes later. Leaving
 * it empty means the platform has no better answer than the moment it was
 * opened, and says so by using that.
 */
final class OpenIncidentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
            'severity' => ['required', Rule::enum(AlertSeverity::class)],
            'started_at' => ['nullable', 'date'],
            'is_public' => ['boolean'],
        ];
    }
}
