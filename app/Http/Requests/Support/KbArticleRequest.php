<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Domain\Support\ArticleVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class KbArticleRequest extends FormRequest
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
            'category_id' => ['nullable', 'string', 'exists:kb_categories,id'],
            'title' => ['required', 'string', 'max:191'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:100000'],
            'visibility' => ['required', Rule::enum(ArticleVisibility::class)],
            'published_at' => ['nullable', 'date'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
