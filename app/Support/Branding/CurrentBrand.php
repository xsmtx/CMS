<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Application\Branding\ResolveBrand;
use App\Application\Shared\ResolveSeller;
use App\Domain\Branding\Brand;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use App\Support\Organizations\OrganizationContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Whose brand this request is showing.
 *
 * Four surfaces, four answers, and each one already has a rule this
 * platform follows elsewhere:
 *
 * | Surface | Whose brand | Why |
 * | --- | --- | --- |
 * | Storefront | the installation's organization | ADR 0020: a public page's boundary comes from the installation, not an actor |
 * | Client area | the organization that **sells** to this customer | a customer buys from their reseller, not from the provider behind it |
 * | Admin | the staff member's own organization | reseller staff see their own brand |
 * | Documents | the organization the document belongs to | an invoice keeps the brand it was issued under, forever |
 *
 * The client-area answer is the one that is easy to get wrong. A customer
 * **is** an organization in this platform, so asking for "the customer's
 * brand" would show them their own name. What they should see is the brand
 * of whoever sells to them — the same question `ResolveSeller` already
 * answers for document numbering and support departments.
 *
 * `config('app.name')` survives as the fallback of last resort, for a fresh
 * installation nobody has branded yet. It is the only place in the codebase
 * that still reads it.
 */
final readonly class CurrentBrand
{
    public function __construct(
        private ResolveBrand $brands,
        private ResolveSeller $sellers,
        private OrganizationContext $organizations,
        private CurrentActor $actor,
        private CurrentCustomer $customer,
    ) {}

    /**
     * The brand for whoever is looking, whichever surface they are on.
     */
    public function current(): Brand
    {
        if ($this->actor->isClient()) {
            return $this->forCustomer();
        }

        $organizationId = $this->actor->organizationId() ?? $this->organizations->id();

        return $organizationId === null
            ? $this->unbranded()
            : $this->brands->forOrganization($organizationId);
    }

    /**
     * The brand a public page shows.
     *
     * Taken from the installation's organization, narrowed to exactly one
     * (ADR 0020) rather than to the subtree a boundary usually means: a
     * storefront shows one brand's catalogue and one brand's name.
     */
    public function forStorefront(): Brand
    {
        $organizationId = $this->organizations->id();

        return $organizationId === null
            ? $this->unbranded()
            : $this->brands->forOrganization($organizationId);
    }

    /**
     * The brand a document was issued under.
     *
     * Resolved from the document's own organization, which for anything a
     * customer owns is the customer — so the seller is resolved from it,
     * the same walk `AllocateNumber` makes.
     */
    public function forDocument(string $organizationId): Brand
    {
        return $this->brands->forOrganization(
            $this->sellers->forOrganization($organizationId),
        );
    }

    private function forCustomer(): Brand
    {
        try {
            $customer = $this->customer->model();
        } catch (NotFoundHttpException) {
            // Signed in on the client guard with no customer behind it.
            // Rare, and not a reason to fail rendering a page.
            return $this->unbranded();
        }

        return $this->forDocument($customer->organization_id);
    }

    private function unbranded(): Brand
    {
        return new Brand(name: (string) config('app.name'));
    }
}
