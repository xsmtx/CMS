<?php

declare(strict_types=1);

namespace App\Http\Requests\Network;

use App\Application\Network\RequestNetworkChange;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for a change to a device's configuration.
 *
 * `intended` is the whole configuration rather than a fragment, and the rule
 * says so: the diff is computed against what the device has, and a fragment
 * would diff as "everything else deleted". A vendor whose configuration
 * arrives in pieces needs an adapter that says so, not a form that pretends.
 *
 * `exists` names the **table**, which is `resource_nodes` — the mistake this
 * product has found twice on `departments`, so the rule is written out with
 * the table beside it.
 */
final class RequestNetworkChangeRequest extends FormRequest
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
            'device' => ['required', 'string', 'exists:resource_nodes,id'],
            'summary' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:2000'],
            'ticket' => ['nullable', 'string', 'max:64'],
            'intended' => ['required', 'string', 'max:2000000'],
        ];
    }

    public function use(): RequestNetworkChange
    {
        return app(RequestNetworkChange::class);
    }
}
