<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Domains\Contracts\DomainPricing;
use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Infrastructure\Domains\NullDomainPricing;
use App\Infrastructure\Risk\AllowAllRiskEvaluator;
use App\Infrastructure\Risk\RuleBasedRiskEvaluator;
use App\Infrastructure\Tax\ConfigurableTaxCalculator;
use App\Infrastructure\Tax\FlatRateTaxCalculator;
use App\Infrastructure\Tax\NoTaxCalculator;
use Illuminate\Support\ServiceProvider;

/**
 * Chooses the tax, risk and domain implementations an installation runs.
 *
 * Every one of them is a contract with a deliberately dull default. Core
 * never implements a country's tax rules, never names a fraud vendor and
 * never talks to a registrar; a module binds its own over these in Phase 12
 * without ordering code changing.
 */
final class OrderingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Tax, in three drivers and one default.
         *
         * `rules` is the default and reads the rows an operator wrote on the Tax
         * screen (ADR 0045). With no rows it charges nothing, which is exactly
         * what an installation that has never opened that screen did before the
         * table existed — so the default changing breaks nobody.
         *
         * `flat` stays for an installation that had it in `.env` and has not
         * moved yet, and `none` stays because "definitely no tax" should be
         * statable without deleting rows. A module still binds over all three.
         */
        $this->app->bind(TaxCalculator::class, fn (): TaxCalculator => match (config('platform.tax.driver', 'rules')) {
            'none' => new NoTaxCalculator,
            'flat' => new FlatRateTaxCalculator(
                rate: (string) config('platform.tax.flat.rate', '0'),
                name: (string) config('platform.tax.flat.name', 'VAT'),
                countryCode: config('platform.tax.flat.country'),
                exemptBusinessesAbroad: (bool) config('platform.tax.flat.exempt_businesses_abroad', false),
            ),
            default => $this->app->make(ConfigurableTaxCalculator::class),
        });

        $this->app->bind(RiskEvaluator::class, function (): RiskEvaluator {
            if (! config('platform.risk.enabled', true)) {
                return new AllowAllRiskEvaluator;
            }

            /** @var array<string, mixed> $rules */
            $rules = config('platform.risk.rules', []);

            return new RuleBasedRiskEvaluator($rules);
        });

        // Phase 7 binds a registrar over this. Until then a domain cannot
        // be priced, and therefore cannot be ordered.
        $this->app->bind(DomainPricing::class, NullDomainPricing::class);
    }
}
