<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Security\AbuseKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a complaint.
 *
 * `occurred_at` is optional and is **not** "now": it is when the complaint
 * says the thing happened, which is what attribution is done against. Left
 * empty, the platform has no better answer than the moment it was recorded
 * and says so by using that.
 *
 * `subject_value` is required only when a subject kind was chosen, because a
 * complaint that named neither an address nor a domain is a real complaint —
 * "your customer is phishing, here is a screenshot" arrives every week.
 */
final class OpenAbuseCaseRequest extends FormRequest
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
        $named = in_array($this->input('subject_type'), ['ip', 'domain'], true);

        return [
            'summary' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(AbuseKind::class)],
            'severity' => ['required', Rule::enum(AlertSeverity::class)],
            'subject_type' => ['nullable', Rule::in(['ip', 'domain', 'none'])],
            'subject_value' => [Rule::requiredIf($named), 'nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:160'],
            'external_reference' => ['nullable', 'string', 'max:160'],
        ];
    }
}
