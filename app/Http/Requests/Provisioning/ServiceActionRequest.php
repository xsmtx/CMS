<?php

declare(strict_types=1);

namespace App\Http\Requests\Provisioning;

use App\Domain\Provisioning\ServiceOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for an operation on a service.
 *
 * `create` is not accepted here: setting up has its own route, its own
 * permission and its own job with its own failure state.
 */
final class ServiceActionRequest extends FormRequest
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
            'operation' => ['required', Rule::in([
                ServiceOperation::Suspend->value,
                ServiceOperation::Unsuspend->value,
                ServiceOperation::Terminate->value,
                ServiceOperation::Sync->value,
            ])],
            'reason' => ['nullable', 'string', 'max:191'],
        ];
    }
}
