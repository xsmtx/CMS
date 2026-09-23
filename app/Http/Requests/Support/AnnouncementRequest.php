<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Domain\Support\ArticleVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:50000'],
            'visibility' => ['required', Rule::enum(ArticleVisibility::class)],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}
