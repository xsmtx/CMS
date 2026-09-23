<?php

declare(strict_types=1);

namespace App\Domain\Licensing\Contracts;

/**
 * Whether this installation is allowed a commercial feature.
 *
 * One question, deliberately. The licence control plane is a separate
 * system this repository does not contain
 * ([ADR 0013](../../../../docs/adr/0013-licensing-control-plane-separation.md)),
 * so what lives here is the **seam**, not the policy — exactly as tax and
 * risk got seams in Phase 3.
 *
 * The rule that matters is the one this contract exists to enforce:
 * **nowhere in this codebase does anything ask what edition it is.** A
 * `if ($licence === 'enterprise')` scattered through the code is a licence
 * model welded into the product, and it is wrong the first time the
 * commercial packaging changes — which it always does.
 */
interface Entitlements
{
    public function allows(string $feature): bool;
}
