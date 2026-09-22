<?php

declare(strict_types=1);

namespace App\Http\Requests\Ordering;

use Illuminate\Foundation\Http\FormRequest;

final class OrderReviewRequest extends FormRequest
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
            // Required here, unlike an ordinary status change: overriding
            // the platform's own judgement is exactly the decision that has
            // to be explainable a year later.
            'reason' => ['required', 'string', 'min:3', 'max:512'],
        ];
    }
}
