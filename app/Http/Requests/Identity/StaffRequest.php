<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\AccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The policy decides; this request only shapes the input.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The route parameter is a bound model on update and absent on
        // create; either way the unique rule needs the key, not the object.
        $current = $this->route('staff');
        $ignore = $current instanceof Model ? $current->getKey() : $current;

        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:191',
                Rule::unique('staff_users', 'email')->ignore($ignore),
            ],
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['string', 'ulid'],
        ];
    }
}
