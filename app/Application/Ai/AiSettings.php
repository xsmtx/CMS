<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Application\Shared\ResolveSeller;
use App\Infrastructure\Ai\Models\AiSetting;
use App\Support\Organizations\OrganizationContext;

/**
 * Whether this seller uses an assistant, and for what (ADR 0050).
 *
 * The seller's, through `ResolveSeller`, like billing terms and tax rules:
 * whether a customer's words may be sent to a vendor is a question with an
 * owner, and on a reseller installation that owner is the reseller. Asking the
 * *current* organization would ask a customer whether their own words may be
 * sent, which is not a question this screen poses.
 *
 * **A seller with no row has no assistant, and no row is written on read.**
 * `BillingSettings` learned the general form of that; here it matters more.
 * The default is "do not send anything to anybody", and a row saying so is a
 * row somebody could flip without ever noticing that it had not been a
 * decision. An absent row cannot be flipped by accident.
 *
 * Nothing is memoised, for the reason `BillingSettings` records at length: a
 * per-seller cache caches the **miss**, holds it across a save, and reads as a
 * settings page that did not save.
 *
 * Read outside the boundary and filtered by the seller — a seller is never
 * inside its own customer's subtree — with the query executed inside the
 * callback, because a builder handed back out is scoped again by the time
 * anybody calls `first()` on it.
 */
final readonly class AiSettings
{
    public function __construct(
        private OrganizationContext $organizations,
        private ResolveSeller $sellers,
    ) {}

    public function forOrganization(string $organizationId): AiSetting
    {
        return $this->forSeller($this->sellers->forOrganization($organizationId));
    }

    public function forSeller(string $sellerId): AiSetting
    {
        $found = $this->organizations->withoutBoundary(
            static fn (): ?AiSetting => AiSetting::query()
                ->where('organization_id', $sellerId)
                ->first(),
        );

        return $found ?? $this->off($sellerId);
    }

    /**
     * The shipped state: no provider, no feature, nothing leaves.
     *
     * Not saved, and deliberately indistinguishable in behaviour from a
     * seller who turned everything off — because they are the same answer and
     * a platform that treated "never asked" as different would eventually
     * treat one of them as consent.
     */
    private function off(string $sellerId): AiSetting
    {
        $setting = new AiSetting;

        $setting->organization_id = $sellerId;
        $setting->provider_key = null;
        $setting->enabled_features = [];

        return $setting;
    }
}
