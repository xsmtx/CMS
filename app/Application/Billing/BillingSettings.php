<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Shared\ResolveSeller;
use App\Infrastructure\Billing\Models\BillingSetting;
use App\Support\Organizations\OrganizationContext;

/**
 * A seller's billing terms, whether or not anybody has stated them.
 *
 * The one place that answers "how long does this customer have to pay" and "what
 * does being late cost them". It reads the **seller's** row — a customer is an
 * organization here, so asking for the current organization's terms in the client
 * area would ask the customer what their own terms are — and a seller with no
 * row falls back to `config('platform.billing')`, so an installation that set
 * `INVOICE_DUE_DAYS` in its environment file behaves exactly as it did before
 * this table existed.
 *
 * The fallback is a **saved-nothing model, not a saved row**: writing a row on
 * first read would mean a fresh installation could never pick up a changed
 * default, and an audit trail would show terms nobody set.
 *
 * **Nothing is memoised here, and one attempt to was a bug.** A per-seller cache
 * looks free — a renewal sweep asks the same question once per invoice — but it
 * caches the *miss* as well as the hit, and the miss is a model saying "nobody
 * has stated these". Held across a save, that answer outlives the operator
 * stating them: the screen redirects, re-renders from the cached default, and
 * reads as a form that did not save. A single indexed lookup per call is the
 * cheaper mistake.
 *
 * Read outside the boundary and filtered by the seller, for the reason
 * `SellerDepartments` documents: a seller is never inside its own customer's
 * subtree, so the boundary would hide exactly the row the client area needs. The
 * query is executed inside the callback — a builder handed back out is scoped
 * again by the time anyone calls `first()` on it, and the symptom is an empty
 * result with no error.
 */
final readonly class BillingSettings
{
    public function __construct(
        private OrganizationContext $organizations,
        private ResolveSeller $sellers,
    ) {}

    /**
     * The terms that apply to a document owned by this organization.
     */
    public function forOrganization(string $organizationId): BillingSetting
    {
        return $this->forSeller($this->sellers->forOrganization($organizationId));
    }

    public function forSeller(string $sellerId): BillingSetting
    {
        $found = $this->organizations->withoutBoundary(
            static fn (): ?BillingSetting => BillingSetting::query()
                ->where('organization_id', $sellerId)
                ->first(),
        );

        return $found ?? $this->defaults($sellerId);
    }

    /**
     * What this installation charges on when nobody has said.
     *
     * Not saved. A row written on read is a row an operator never agreed to,
     * and it would freeze today's configured default into the database where
     * the next deploy's change could not reach it.
     */
    private function defaults(string $sellerId): BillingSetting
    {
        $setting = new BillingSetting;

        $setting->organization_id = $sellerId;
        $setting->due_days = (int) config('platform.billing.due_days', 14);
        $setting->late_fee_rate_ppm = 0;

        return $setting;
    }
}
