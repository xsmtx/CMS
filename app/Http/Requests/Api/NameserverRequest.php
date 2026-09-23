<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class NameserverRequest extends FormRequest
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
            // Two is what every registry requires and four is what most
            // accept. Refusing one here is kinder than having the registry
            // refuse it an hour later in a queue.
            'nameservers' => ['required', 'array', 'min:2', 'max:4'],
            'nameservers.*' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'],
        ];
    }
}
