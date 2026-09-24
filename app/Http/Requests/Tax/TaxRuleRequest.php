<?php

declare(strict_types=1);

namespace App\Http\Requests\Tax;

use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxCustomerKind;
use App\Domain\Tax\TaxRuleAttributes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A rate an operator typed, turned into an integer once.
 *
 * The screen asks for a percentage, because that is what a tax authority
 * publishes and what an accountant says out loud. It arrives as a decimal string
 * and becomes parts per million **here**, in one place, so nothing downstream
 * ever holds a rate as a float.
 *
 * The rate is validated as a string with a pattern rather than as `numeric`,
 * which is the same reason the currency rate is: `numeric` would accept
 * `1e2` and `0x14`, and casting either to a float is how a rate stops being the
 * number somebody typed.
 */
final class TaxRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],

            // Up to four decimal places, and never more than 100%: Quebec needs
            // three, and nothing anybody charges needs a fifth.
            'rate' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],

            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'region_code' => ['nullable', 'string', 'max:8'],
            'postcode_pattern' => ['nullable', 'string', 'max:32'],

            'level' => ['required', 'integer', 'min:1', 'max:2'],
            'compound' => ['sometimes', 'boolean'],

            'applies_to' => ['required', Rule::enum(TaxAppliesTo::class)],
            'customer_kind' => ['required', Rule::enum(TaxCustomerKind::class)],

            'exempts_validated_business' => ['sometimes', 'boolean'],
            'exemption_note' => ['nullable', 'string', 'max:191'],

            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],

            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toAttributes(): TaxRuleAttributes
    {
        return new TaxRuleAttributes(
            name: $this->string('name')->toString(),
            // Times ten thousand, rounded once, as an integer. `(int) round()`
            // rather than a cast: `(int) (9.975 * 10000)` is 99749 on a binary
            // float, which would charge Quebec a rate nobody has ever set.
            ratePartsPerMillion: (int) round(((float) $this->string('rate')->toString()) * 10_000),
            countryCode: $this->upperOrNull('country_code'),
            regionCode: $this->upperOrNull('region_code'),
            postcodePattern: $this->input('postcode_pattern'),
            level: $this->integer('level', 1),
            compound: $this->boolean('compound'),
            appliesTo: TaxAppliesTo::from($this->string('applies_to')->toString()),
            customerKind: TaxCustomerKind::from($this->string('customer_kind')->toString()),
            exemptsValidatedBusiness: $this->boolean('exempts_validated_business'),
            exemptionNote: $this->input('exemption_note'),
            priority: $this->integer('priority'),
            startsOn: $this->input('starts_on'),
            endsOn: $this->input('ends_on'),
            isActive: $this->boolean('is_active'),
            notes: $this->input('notes'),
        );
    }

    /**
     * Country and region codes are stored upper case, so a rule typed as `gb`
     * matches a supply that says `GB`. Comparing case-insensitively as well
     * would work and would leave two spellings in the table for an operator to
     * wonder about.
     */
    private function upperOrNull(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : strtoupper($value);
    }
}
