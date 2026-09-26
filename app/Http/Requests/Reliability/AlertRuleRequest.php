<?php

declare(strict_types=1);

namespace App\Http\Requests\Reliability;

use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertSubject;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A rule an operator writes.
 *
 * **The threshold becomes parts per million here, once.** The same decision
 * `TaxRuleRequest` made and for the same reason: a number that is converted
 * in two places is a number the two places eventually disagree about. It is
 * validated as a **string against a regex** rather than as `numeric`, because
 * `numeric` accepts `1e2` — which is a hundred, written in a way nobody
 * typing a threshold meant.
 *
 * What the threshold *means* depends on the subject, and the form says so
 * rather than the validator guessing: a percentage for a metric that is a
 * ratio, a number of days for a capacity forecast. Both are stored as the
 * bare number times a million, so 90% is 900,000 and 14 days is 14,000,000.
 */
final class AlertRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', Rule::enum(AlertSubject::class)],
            'target' => ['nullable', 'string', 'max:120'],
            'comparison' => ['nullable', Rule::enum(AlertComparison::class)],
            // A string against a regex: `numeric` would accept `1e2`.
            'threshold' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'for_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'severity' => ['required', Rule::enum(AlertSeverity::class)],
            'enabled' => ['boolean'],
            'notify' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The row, with the conversion done.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $data = $this->validated();
        $subject = AlertSubject::from($data['subject']);

        // A subject that is not a number carries neither — a rule that stored
        // "above 90" beside "the scheduler has stopped" would be a rule whose
        // screen could not describe it.
        $numeric = $subject->isNumeric();

        return [
            'organization_id' => app(OrganizationContext::class)->id(),
            'name' => $data['name'],
            'subject' => $subject,
            'target' => $subject->needsTarget() || $subject === AlertSubject::Metric
                ? ($data['target'] ?? null)
                : null,
            'comparison' => $numeric && isset($data['comparison'])
                ? AlertComparison::from($data['comparison'])
                : null,
            'threshold_ppm' => $numeric && isset($data['threshold'])
                ? (int) round(((float) $data['threshold']) * 1_000_000)
                : null,
            'for_minutes' => (int) $data['for_minutes'],
            'severity' => AlertSeverity::from($data['severity']),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'notify' => (bool) ($data['notify'] ?? true),
            'note' => $data['note'] ?? null,
        ];
    }
}
