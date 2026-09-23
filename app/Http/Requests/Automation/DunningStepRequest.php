<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Domain\Automation\DunningAction;
use App\Domain\Notifications\NotificationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DunningStepRequest extends FormRequest
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
            // A year either side. Anything beyond that is a configuration
            // mistake rather than a policy.
            'offset_days' => ['required', 'integer', 'min:-365', 'max:365'],
            'action' => ['required', Rule::enum(DunningAction::class)],
            'event' => [
                'nullable',
                Rule::enum(NotificationEvent::class),
                Rule::requiredIf(fn (): bool => $this->input('action') === DunningAction::Notify->value),
            ],
        ];
    }
}
