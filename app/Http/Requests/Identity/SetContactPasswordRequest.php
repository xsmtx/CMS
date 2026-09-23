<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * An operator setting a customer's password for them.
 *
 * The same strength rules a customer has to meet. A support desk that can
 * set a weaker password than the customer could choose has made the policy
 * advisory, and the weak one will be the one that stays.
 *
 * A reason is required. Setting somebody else's password is the single
 * most abusable thing a support desk can do, and "because they asked on
 * the phone" in an audit record is the difference between a procedure and
 * an incident.
 */
final class SetContactPasswordRequest extends FormRequest
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
            'password' => ['required', 'confirmed', Password::defaults()],
            'reason' => ['required', 'string', 'min:5', 'max:191'],
        ];
    }
}
