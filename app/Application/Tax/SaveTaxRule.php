<?php

declare(strict_types=1);

namespace App\Application\Tax;

use App\Domain\Tax\TaxRuleAttributes;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Writing down what to charge.
 *
 * A use case rather than a controller's `create()`, for one reason: this changes
 * what every future invoice says, and the audit row is the only way to answer
 * "who raised the rate in March" six months later. The record names the rate
 * before and after, because the rate is the whole point of the row.
 *
 * It never touches an issued document. An invoice is frozen on issue (ADR 0023)
 * with its own `tax_minor`, per-line rate and breakdown, so editing a rule
 * changes the next one and nothing that has been sent.
 */
final readonly class SaveTaxRule
{
    /** The columns whose change is worth naming in the audit row. */
    private const array AUDITED = ['name', 'rate_ppm', 'country_code', 'region_code', 'is_active'];

    public function handle(
        string $organizationId,
        TaxRuleAttributes $attributes,
        ?TaxRule $existing = null,
        ?Model $actor = null,
    ): TaxRule {
        $values = [
            'name' => $attributes->name,
            'country_code' => $attributes->countryCode,
            'region_code' => $attributes->regionCode,
            'postcode_pattern' => $attributes->postcodePattern,
            'rate_ppm' => $attributes->ratePartsPerMillion,
            'level' => $attributes->level,
            'compound' => $attributes->compound,
            'applies_to' => $attributes->appliesTo->value,
            'customer_kind' => $attributes->customerKind->value,
            'exempts_validated_business' => $attributes->exemptsValidatedBusiness,
            'exemption_note' => $attributes->exemptionNote,
            'priority' => $attributes->priority,
            'starts_on' => $attributes->startsOn,
            'ends_on' => $attributes->endsOn,
            'is_active' => $attributes->isActive,
            'notes' => $attributes->notes,
        ];

        if ($existing instanceof TaxRule) {
            $before = $existing->only(self::AUDITED);

            $existing->update($values);

            Audit::action('tax.rule.updated')
                ->by($actor)
                ->on($existing)
                ->forOrganization($existing->organization_id)
                ->changed($before, $existing->only(self::AUDITED))
                ->write();

            return $existing;
        }

        $rule = TaxRule::query()->create([...$values, 'organization_id' => $organizationId]);

        Audit::action('tax.rule.created')
            ->by($actor)
            ->on($rule)
            ->forOrganization($organizationId)
            ->withMetadata(['rate' => $rule->percentage(), 'country' => $rule->country_code])
            ->write();

        return $rule;
    }
}
