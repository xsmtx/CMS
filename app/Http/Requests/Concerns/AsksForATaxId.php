<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Application\Tax\TaxIdentity;

/**
 * The rule for the tax id field, stated once.
 *
 * Three forms ask for a tax id beside a company name — checkout, the client's
 * billing details, and the admin customer form — and the seller's answer to
 * "must a business give one" has to be the same on all three. Copied into three
 * request classes it would be one rule in three places, and the one somebody
 * forgot to update would be the form a customer actually used.
 *
 * The rule fires on **company name**, not on a tax id being absent, because
 * that is what makes a customer a business (`Customer::isBusiness()`). The
 * message names the field the way this seller names it, so a Turkish customer is
 * asked for a Vergi Numarası rather than for a VAT number.
 */
trait AsksForATaxId
{
    /**
     * @return list<string>
     */
    protected function taxIdRules(string $companyField = 'company_name'): array
    {
        $rules = ['nullable', 'string', 'max:64'];

        if (! app(TaxIdentity::class)->requiredForBusiness()) {
            return $rules;
        }

        // `required_with` rather than `required`: an individual is not a
        // business and is asked for nothing.
        return ['required_with:'.$companyField, 'nullable', 'string', 'max:64'];
    }

    /**
     * @return array<string, string>
     */
    protected function taxIdMessages(): array
    {
        return [
            'tax_id.required_with' => (string) __('tax.identity.required', [
                'label' => app(TaxIdentity::class)->label(),
            ]),
        ];
    }
}
