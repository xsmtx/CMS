<?php

declare(strict_types=1);

namespace App\Http\Requests\Branding;

use App\Domain\Branding\Surface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ThemeRequest extends FormRequest
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
            'surface' => ['required', Rule::enum(Surface::class)],
            // A slug, not a path. Anything with a separator in it would be
            // a directory traversal wearing a theme's name.
            'theme' => ['required', 'string', 'max:96', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
        ];
    }
}
