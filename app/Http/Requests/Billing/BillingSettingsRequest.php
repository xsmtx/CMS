<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\BillingSettingsAttributes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A seller's terms, as a form sends them.
 *
 * The late-fee rate is a percentage typed by a human and becomes parts per
 * million here, once, exactly as a tax rate does — and validated as a string
 * against a pattern for the same reason: `numeric` accepts `1e2` and `0x14`, and
 * casting either to a float is how a rate stops being the number somebody typed.
 */
final class BillingSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Zero is "on receipt", which several jurisdictions require of a
            // consumer sale. The ceiling is a year: a due date further out than
            // that is a typo, and a typo here silently stops dunning.
            'due_days' => ['required', 'integer', 'min:0', 'max:365'],

            // Up to four decimal places and never above 100%: a fee larger than
            // the debt is not a fee.
            'late_fee_rate' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],

            'late_fee_label' => ['nullable', 'string', 'max:64'],
            'document_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toAttributes(): BillingSettingsAttributes
    {
        return new BillingSettingsAttributes(
            dueDays: $this->integer('due_days'),
            // `(int) round()` rather than a cast: `(int) (1.5 * 10000)` is fine
            // and `(int) (9.975 * 10000)` is 99749, so the safe form is used
            // everywhere a rate is converted rather than only where it bites.
            lateFeeRatePartsPerMillion: (int) round(((float) $this->string('late_fee_rate')->toString()) * 10_000),
            lateFeeLabel: $this->input('late_fee_label'),
            documentNote: $this->input('document_note'),
        );
    }
}
