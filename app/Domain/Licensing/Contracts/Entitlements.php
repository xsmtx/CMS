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

    /**
     * A numeric ceiling this licence sets, or null when nothing caps it.
     *
     * A second method rather than `allows('max_staff_users:5')`, because a
     * limit is a number and a caller that had to parse it out of a string
     * would be a caller that could parse it wrongly.
     *
     * **Null, never zero, for "no limit".** Zero is a real answer — "no
     * reseller accounts at all" — and a caller reading the two as the same
     * would cap an unlimited licence at nothing.
     */
    public function limit(string $name): ?int;
}
