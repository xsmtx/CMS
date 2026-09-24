<?php

declare(strict_types=1);

namespace App\Application\Tax;

use App\Application\Shared\ResolveSeller;
use App\Domain\Tax\TaxRounding;
use App\Infrastructure\Tax\Models\TaxSetting;
use App\Support\Organizations\OrganizationContext;

/**
 * The seller's tax settings for whoever is being served right now.
 *
 * One place that resolves "whose settings apply" so that the three readers of
 * them — the calculator, the vocabulary on forms, and the arithmetic that
 * composes a document — cannot disagree about it. The seller's, never the
 * customer's: a customer is an organization here, so asking the client area for
 * "the current organization's settings" would ask the customer.
 *
 * Nothing is memoised. A settings reader that cached a miss is how the billing
 * terms screen briefly looked like a form that did not save, and a single
 * indexed lookup is the cheaper mistake.
 */
final readonly class CurrentTaxSettings
{
    public function __construct(
        private TaxRules $rules,
        private ResolveSeller $sellers,
        private OrganizationContext $organizations,
    ) {}

    public function get(): TaxSetting
    {
        $organizationId = $this->organizations->id();

        if ($organizationId === null) {
            return new TaxSetting;
        }

        return $this->rules->settings($this->sellers->forOrganization($organizationId));
    }

    /**
     * Whether each line is taxed and rounded on its own.
     *
     * The difference is a cent or two on a document with several lines, and
     * which one a jurisdiction requires is a real difference rather than a
     * preference — which is why it is a setting and not a constant.
     */
    public function roundsPerLine(): bool
    {
        return $this->get()->rounding === TaxRounding::PerLine;
    }
}
