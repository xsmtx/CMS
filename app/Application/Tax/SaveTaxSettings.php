<?php

declare(strict_types=1);

namespace App\Application\Tax;

use App\Domain\Tax\TaxSettingsAttributes;
use App\Infrastructure\Tax\Models\TaxSetting;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * The answers that are not a rate.
 *
 * `prices_include_tax` is the one worth the audit row on its own: flipping it
 * does not change a stored price by a cent and changes what every displayed
 * price *means*. An operator who turns it on by accident has quietly cut their
 * own margin, and the only way to find out when is this record.
 */
final readonly class SaveTaxSettings
{
    private const array AUDITED = ['prices_include_tax', 'rounding', 'require_tax_id_for_business'];

    public function handle(
        string $organizationId,
        TaxSettingsAttributes $attributes,
        ?Model $actor = null,
    ): TaxSetting {
        $setting = TaxSetting::query()->firstOrNew(['organization_id' => $organizationId]);

        $before = $setting->only(self::AUDITED);

        $setting->fill([
            'organization_id' => $organizationId,
            'prices_include_tax' => $attributes->pricesIncludeTax,
            'rounding' => $attributes->rounding->value,
            'tax_id_label' => $attributes->taxIdLabel,
            'require_tax_id_for_business' => $attributes->requireTaxIdForBusiness,
            'exemption_note' => $attributes->exemptionNote,
        ])->save();

        Audit::action('tax.settings.updated')
            ->by($actor)
            ->on($setting)
            ->forOrganization($organizationId)
            ->changed($before, $setting->only(self::AUDITED))
            ->write();

        return $setting;
    }
}
