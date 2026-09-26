<?php

declare(strict_types=1);

namespace App\Http\Requests\Reliability;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The document that hangs off a resolved incident.
 *
 * **Nullable on purpose.** Clearing it is a real thing to want — a first
 * draft written at four in the morning, read back over coffee, and taken down
 * until somebody has time to write it properly. A field that can only ever be
 * filled in is a field people leave wrong.
 *
 * Read with `??` in the controller, because `validate()` returns only the
 * keys that were submitted: a textarea the operator emptied is *absent*
 * rather than null, and `$data['postmortem']` on its own is a 500.
 */
final class PostmortemRequest extends FormRequest
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
            'postmortem' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
