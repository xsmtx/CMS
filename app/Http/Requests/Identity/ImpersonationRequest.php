<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class ImpersonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Required and stored. A review with no stated intent tells
            // nobody anything, and review is the only control that makes
            // impersonation acceptable at all.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
