<?php

declare(strict_types=1);

namespace App\Http\Requests\Provisioning;

use App\Domain\Provisioning\PlacementStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ServerGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:96'],
            'placement_strategy' => ['required', Rule::enum(PlacementStrategy::class)],
            'region' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
