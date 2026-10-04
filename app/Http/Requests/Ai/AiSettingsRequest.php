<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Domain\Ai\AiFeature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Turning the assistant on, or off (ADR 0050).
 *
 * `authorize()` is true because the route carries `owner`, and that check
 * runs **above** this class — the rule Phase 17 learned on the Licence screen
 * and three phases relearned: with the check inside, somebody who may not
 * touch the screen is asked to fill in a form and *then* refused.
 *
 * The features are validated against the enum rather than against a list,
 * so a feature added next phase is offerable without editing this file and
 * a value that is not one is refused rather than stored.
 */
final class AiSettingsRequest extends FormRequest
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
            // Empty means off, which is a real answer and the shipped one.
            'provider' => ['nullable', 'string', 'max:64'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_column(AiFeature::cases(), 'value'))],
            // The seller's own wording, attached to every draft. Bounded
            // because it is sent on every call and somebody pasting a manual
            // in here would pay for it on each one.
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider' => (string) __('ai.provider'),
            'instructions' => (string) __('ai.instructions'),
        ];
    }
}
