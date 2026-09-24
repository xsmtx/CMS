<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Billing\BillingSettingsAttributes;
use App\Infrastructure\Billing\Models\BillingSetting;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * A seller's terms, stated.
 *
 * Every field here is audited, which is unusual — most settings screens record
 * only the ones that change arithmetic. These all do. `due_days` decides when an
 * invoice becomes overdue and therefore when dunning starts touching somebody's
 * services; `late_fee_rate_ppm` is money charged to a customer; and
 * `document_note` is printed on a legal document, so "when did this sentence
 * change" is a question an auditor is entitled to ask.
 */
final readonly class SaveBillingSettings
{
    private const array AUDITED = ['due_days', 'late_fee_rate_ppm', 'late_fee_label', 'document_note'];

    public function handle(
        string $organizationId,
        BillingSettingsAttributes $attributes,
        ?Model $actor = null,
    ): BillingSetting {
        $setting = BillingSetting::query()->firstOrNew(['organization_id' => $organizationId]);

        $before = $setting->only(self::AUDITED);

        $setting->fill([
            'organization_id' => $organizationId,
            'due_days' => $attributes->dueDays,
            'late_fee_rate_ppm' => $attributes->lateFeeRatePartsPerMillion,
            'late_fee_label' => $attributes->lateFeeLabel,
            'document_note' => $attributes->documentNote,
        ])->save();

        Audit::action('billing.settings.updated')
            ->by($actor)
            ->on($setting)
            ->forOrganization($organizationId)
            ->changed($before, $setting->only(self::AUDITED))
            ->write();

        return $setting;
    }
}
