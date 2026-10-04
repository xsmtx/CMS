<?php

declare(strict_types=1);

namespace App\Http\Requests\Vendors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putting one seat of a pool on one machine (§24).
 *
 * There is deliberately **no licence key field**. A key is a credential for
 * somebody's production panel, nothing in this platform would ever read one,
 * and a column that held it would be a secret stored for no reason
 * (non-negotiable 7). The vendor's own line-item reference is what an
 * operator actually needs when they ring up about a seat.
 */
final class LicenceAllocationRequest extends FormRequest
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
            // A machine in this installation's own fleet. An allocation to a
            // server nobody recorded would be a row with no second half, and
            // the second half is the whole point.
            'server_id' => ['required', 'string', Rule::exists('servers', 'id')],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'server_id' => (string) __('vendors.licences.server'),
        ];
    }
}
