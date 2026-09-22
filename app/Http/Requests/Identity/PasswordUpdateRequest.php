<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\Guard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class PasswordUpdateRequest extends FormRequest
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
            // The current password is required even though the session is
            // already authenticated: it is what stops an unattended browser
            // from being used to take the account over.
            'current_password' => ['required', 'string', 'current_password:'.$this->guardName()],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ];
    }

    private function guardName(): string
    {
        return (Guard::fromRouteName($this->route()?->getName()) ?? Guard::Staff)->value;
    }
}
