<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Api\ApiScope;
use App\Domain\Api\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a device session.
 *
 * `authorize()` is true because this endpoint *is* the authentication: there
 * is nobody to authorize yet, and the credentials are checked in the
 * controller by the same use case the browser uses.
 *
 * `platform` is validated against the enum and a value outside it is still
 * accepted — `DevicePlatform::match()` reads it as `Other` — because a device
 * nobody can name is still a device somebody has to be able to revoke. So the
 * rule is a string rather than an `in:`, deliberately.
 */
final class DeviceTokenRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],

            // The name that appears beside a Revoke button on the Security
            // screen, so it has to be something a person recognises.
            'device_name' => ['required', 'string', 'max:64'],
            'platform' => ['nullable', 'string', 'max:32'],

            'two_factor_code' => ['nullable', 'string', 'max:64'],

            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', Rule::in(array_column(ApiScope::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'device_name' => (string) __('identity.devices.name'),
            'platform' => (string) __('identity.devices.platform'),
        ];
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::match($this->input('platform'));
    }
}
