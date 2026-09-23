<?php

declare(strict_types=1);

namespace App\Infrastructure\Licensing;

use App\Application\Licensing\LicenceState;
use App\Domain\Licensing\Contracts\Entitlements;

/**
 * What this installation is allowed, according to the licence it holds.
 *
 * The binding that replaces `UnrestrictedEntitlements` when — and only when —
 * a licence has actually been activated. The default stays what it was: an
 * installation nobody licensed is not crippled by a check it has no way to
 * answer.
 *
 * Four states, and the third is the one that matters:
 *
 * | State | Answer |
 * | --- | --- |
 * | No licence ever activated | everything |
 * | A live licence | what the token does not exclude |
 * | Out of contact, inside grace | the same as when contact was last made |
 * | Grace exhausted, suspended or revoked | the unlicensed set |
 *
 * "Inside grace" needs no code here at all, which is the point of putting the
 * grace arithmetic in `LicenceState::isLive()`: this class asks one question
 * and a vendor's outage is invisible to it.
 *
 * **The unlicensed set is not "nothing works".** It is what a self-hosted
 * installation with no commercial relationship gets: the platform runs and the
 * vendor mark comes back. A gate whose exhausted state is an outage is a gate
 * that will one day take a customer down over a DNS failure, and after that
 * nobody trusts the licence check again.
 *
 * **A feature the token does not mention is allowed.** The token lists
 * exclusions, not inclusions, so a feature added to the product after a token
 * was minted works for existing licences instead of switching itself off.
 *
 * The state is read once per request. It is a single row and the read is cheap,
 * but `allows()` is called from render paths and a query per call would be a
 * query per rendered badge.
 */
final class LicensedEntitlements implements Entitlements
{
    private ?LicenceState $state = null;

    public function allows(string $feature): bool
    {
        $state = $this->state();

        // Never licensed: the dull default, unchanged since Phase 11.
        if (! $state->configured) {
            return true;
        }

        if ($state->isLive()) {
            return ! in_array($feature, $state->excluded, strict: true);
        }

        /*
         * Lapsed: suspended, revoked, expired, or out of contact for longer
         * than the grace period.
         *
         * Every member of `Feature` exists *because* a licence turns it on, so
         * a lapse turns all of them off — which today means exactly one thing:
         * the vendor mark comes back. If a feature should ever survive a lapse,
         * this is the line that names it, and it should be named here rather
         * than checked for somewhere else.
         *
         * What a lapse does **not** do is stop the platform working. No screen
         * closes, no order is refused, no service is suspended. A gate whose
         * exhausted state is an outage is a gate that will one day take a
         * customer down over a DNS failure, and after that nobody trusts the
         * licence check again.
         */
        return false;
    }

    /**
     * A numeric entitlement, or null when nothing caps it.
     *
     * Null rather than zero, because zero is a real answer — "no reseller
     * accounts at all" — and a caller reading the two as the same would cap an
     * unlimited licence at nothing.
     */
    public function limit(string $name): ?int
    {
        $state = $this->state();

        if (! $state->configured || ! $state->isLive()) {
            return null;
        }

        return $state->limits[$name] ?? null;
    }

    private function state(): LicenceState
    {
        return $this->state ??= LicenceState::load();
    }
}
