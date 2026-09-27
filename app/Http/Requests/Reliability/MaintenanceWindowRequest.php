<?php

declare(strict_types=1);

namespace App\Http\Requests\Reliability;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Planning work.
 *
 * `after:starts_at` as well as the use case's own check, and that is not
 * duplication: the rule puts the message on the field an operator is looking
 * at, and the use case refuses it for every caller — a module, an import, a
 * console command — none of which passes through a form.
 *
 * `node_keys` is a textarea, one machine per line, and may be empty. Empty
 * means the whole installation, which is what a datacentre power test is.
 */
final class MaintenanceWindowRequest extends FormRequest
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
            'body' => ['nullable', 'string', 'max:4000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'node_keys' => ['nullable', 'string', 'max:20000'],
            'is_public' => ['boolean'],
        ];
    }
}
