<?php

declare(strict_types=1);

namespace App\Http\Requests\Provisioning;

use App\Domain\Provisioning\ServerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A node an operator is adding or correcting.
 *
 * The secret is `nullable` on purpose: leaving it empty on an edit keeps
 * the token that is already stored. A form that required it every time
 * would push operators into keeping credentials somewhere they can paste
 * them from.
 */
final class ServerRequest extends FormRequest
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
            'server_group_id' => ['nullable', 'string', 'exists:server_groups,id'],
            'module' => ['required', 'string', 'max:48'],
            'hostname' => ['required', 'string', 'max:191'],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'secure' => ['boolean'],
            'username' => ['required', 'string', 'max:96'],
            'secret' => ['nullable', 'string', 'max:512'],
            'status' => ['required', Rule::enum(ServerStatus::class)],
            'region' => ['nullable', 'string', 'max:32'],
            'max_services' => ['required', 'integer', 'min:0', 'max:100000'],
            'weight' => ['required', 'integer', 'min:1', 'max:1000'],
            'nameservers' => ['nullable', 'string', 'max:191'],
        ];
    }
}
