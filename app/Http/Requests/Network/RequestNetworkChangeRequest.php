<?php

declare(strict_types=1);

namespace App\Http\Requests\Network;

use App\Application\Network\RequestNetworkChange;
use App\Domain\Network\ChangeTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
 *
 * **A workspace wants neither of those two fields** (§25): there is no
 * configuration to type, because core holds no Terraform code, and the plan is
 * produced by the tool rather than diffed here. So `intended` is required only
 * for a device, and `ref` is the revision — nullable, because "whatever the
 * workspace tracks" is the ordinary case and defaulting to `main` would be
 * this platform planning code nobody named.
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
            'target' => ['nullable', 'string', Rule::enum(ChangeTarget::class)],
            'summary' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:2000'],
            'ticket' => ['nullable', 'string', 'max:64'],
            /*
             * `required_unless`, not `required_if`. A form that posts no
             * target at all is the device form — which is every caller before
             * §25 — and `required_if:target,device` does not fire on an absent
             * field, so the rule would have quietly stopped applying.
             */
            'intended' => ['required_unless:target,workspace', 'nullable', 'string', 'max:2000000'],
            // A branch, a tag or a commit. Bounded well short of anything a
            // revision could be, and never defaulted.
            'ref' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function use(): RequestNetworkChange
    {
        return app(RequestNetworkChange::class);
    }
}
