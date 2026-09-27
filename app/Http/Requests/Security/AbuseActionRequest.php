<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use App\Domain\Security\AbuseAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deciding to do something to an account.
 *
 * The reason is required and is **sent**: for a suspension it lands on the
 * service's own transition beside the operator's name. A reason a screen
 * collects and an endpoint discards is a sentence nobody reads.
 *
 * `service` names a row rather than a table by accident: `exists:services,id`
 * is the table, which is the mistake this product has made twice on
 * `departments`.
 */
final class AbuseActionRequest extends FormRequest
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
            'action' => ['required', Rule::enum(AbuseAction::class)],
            'reason' => ['required', 'string', 'max:2000'],
            'service' => ['nullable', 'string', 'exists:services,id'],
        ];
    }
}
