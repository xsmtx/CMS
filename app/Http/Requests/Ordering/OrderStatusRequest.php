<?php

declare(strict_types=1);

namespace App\Http\Requests\Ordering;

use App\Domain\Ordering\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrderStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(OrderStatus::class)],
            // Not required, but recorded when given: "why is this order
            // cancelled" is asked more often than it is answered.
            'reason' => ['nullable', 'string', 'max:512'],
        ];
    }
}
