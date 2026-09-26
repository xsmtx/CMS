<?php

declare(strict_types=1);

namespace App\Http\Requests\Network;

use App\Domain\Network\IpFamily;
use App\Domain\Network\PoolPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PoolRequest extends FormRequest
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
            'family' => ['required', Rule::enum(IpFamily::class)],
            'purpose' => ['required', Rule::enum(PoolPurpose::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
