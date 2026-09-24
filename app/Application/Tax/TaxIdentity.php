<?php

declare(strict_types=1);

namespace App\Application\Tax;

use App\Application\Shared\ResolveSeller;
use App\Support\Organizations\OrganizationContext;

/**
 * What this seller calls a tax id, and whether a business must give one.
 *
 * Two of the three answers on the tax settings screen that are not a rate, in the
 * one place every form that asks for a tax id can read them.
 *
 * **"VAT number" is wrong in most of the world.** It is Vergi Numarası in Turkey,
 * an ABN in Australia, a GSTIN in India, a CNPJ in Brazil and a UID in
 * Switzerland; a form that says VAT number to a Turkish customer is a form that
 * looks like it was written for somewhere else. That is the whole reason
 * `tax_id_label` exists, and it was doing nothing until this.
 *
 * The seller's, never the customer's — a customer is an organization here, so
 * asking for "the current organization's label" in the client area would ask the
 * customer what they call it.
 */
final readonly class TaxIdentity
{
    public function __construct(
        private TaxRules $rules,
        private ResolveSeller $sellers,
        private OrganizationContext $organizations,
    ) {}

    /**
     * @return array{label: string, requiredForBusiness: bool}
     */
    public function current(): array
    {
        $organizationId = $this->organizations->id();

        if ($organizationId === null) {
            return $this->fallback();
        }

        $settings = $this->rules->settings($this->sellers->forOrganization($organizationId));

        $label = $settings->tax_id_label;

        return [
            'label' => $label === null || trim($label) === '' ? $this->defaultLabel() : trim($label),
            'requiredForBusiness' => $settings->require_tax_id_for_business,
        ];
    }

    /**
     * Whether a business buying from this seller has to state a tax id.
     *
     * Asked by the validation on every form that creates or edits a business
     * customer, so the rule is stated once rather than copied into five request
     * classes that would drift apart.
     */
    public function requiredForBusiness(): bool
    {
        return $this->current()['requiredForBusiness'];
    }

    public function label(): string
    {
        return $this->current()['label'];
    }

    /**
     * @return array{label: string, requiredForBusiness: bool}
     */
    private function fallback(): array
    {
        return ['label' => $this->defaultLabel(), 'requiredForBusiness' => false];
    }

    /**
     * The neutral wording, used until a seller states their own.
     *
     * Deliberately not "VAT number": a default that is wrong for most of the
     * world reads as configured when it is only unset.
     */
    private function defaultLabel(): string
    {
        return (string) __('tax.identity.default_label');
    }
}
