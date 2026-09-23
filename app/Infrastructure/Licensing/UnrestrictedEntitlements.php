<?php

declare(strict_types=1);

namespace App\Infrastructure\Licensing;

use App\Domain\Licensing\Contracts\Entitlements;

/**
 * The default: everything is allowed.
 *
 * A self-hosted installation with no licence server must not be crippled
 * by a check it has no way to answer. A gate whose default is "deny" turns
 * an unreachable licence API into an outage, and an operator whose
 * storefront lost its branding because a vendor's server was down will not
 * distinguish that from the product being broken.
 *
 * So the dull default allows, and a commercial distribution binds
 * something else — which is the same shape as the tax and risk defaults
 * ([ADR 0022](../../../docs/adr/0022-risk-and-tax-are-contracts.md)).
 */
final readonly class UnrestrictedEntitlements implements Entitlements
{
    public function allows(string $feature): bool
    {
        return true;
    }
}
