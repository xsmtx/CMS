<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Domain\Network\Exceptions\GrantRefused;
use App\Domain\Network\GrantableCapability;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\AccessGrant;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;

/**
 * Just-in-time access: giving it, taking it back, and asking who has it
 * (§17).
 *
 * **A grant only ever adds**, which is what makes the gate below safe to ask
 * everywhere. Somebody who already holds the permission is unaffected; a
 * grant is for the engineer who does not, and it runs out on its own.
 *
 * **Nobody grants themselves anything.** Enforced here rather than by the
 * permission, for the reason `DecideNetworkChange` gives: a permission says
 * who may grant and cannot say *to whom*. An operator who could hand
 * themselves Connect for two hours has a permission they do not have, with a
 * paper trail that says somebody agreed.
 *
 * **The window is bounded at both ends.** A grant of one minute is a mistake
 * somebody is about to repeat and a grant of a fortnight is a permission with
 * extra steps — so both are refused, and the maximum is configuration rather
 * than a number in this file.
 */
final readonly class AccessGrants
{
    public function grant(
        StaffUser $holder,
        GrantableCapability $capability,
        StaffUser $granter,
        string $reason,
        CarbonImmutable $expiresAt,
        ?ResourceNode $node = null,
        ?string $ticket = null,
    ): AccessGrant {
        if ($holder->id === $granter->id) {
            throw GrantRefused::ownGrant();
        }

        $minutes = CarbonImmutable::now()->diffInMinutes($expiresAt, absolute: false);

        if ($minutes < 5) {
            throw GrantRefused::tooShort();
        }

        $maximum = (int) config('platform.network.max_grant_minutes', 720);

        if ($minutes > $maximum) {
            throw GrantRefused::tooLong($maximum);
        }

        $grant = AccessGrant::query()->create([
            'organization_id' => $holder->organization_id,
            'staff_user_id' => $holder->id,
            'granted_by' => $granter->id,
            'capability' => $capability,
            'resource_node_id' => $node?->id,
            'reason' => $reason,
            'ticket' => $ticket,
            'expires_at' => $expiresAt,
        ]);

        Audit::action('network.access.granted')
            ->by($granter)
            ->on($grant)
            ->forOrganization($grant->organization_id)
            ->because($reason)
            ->withMetadata([
                'holder' => $holder->name,
                'capability' => $capability->value,
                'expires_at' => $expiresAt->toIso8601String(),
                'node' => $node?->node_key,
            ])
            ->write();

        return $grant;
    }

    /**
     * End it early.
     *
     * Closing rather than deleting, like every other history in this product:
     * "who could get into that box on the eleventh" is the question an
     * incident review arrives as.
     */
    public function revoke(AccessGrant $grant, ?StaffUser $actor, string $reason): AccessGrant
    {
        if ($grant->revoked_at !== null) {
            throw GrantRefused::alreadyRevoked();
        }

        $grant->revoked_at = CarbonImmutable::now();
        $grant->revoked_by = $actor?->id;
        $grant->revocation_reason = $reason;
        $grant->save();

        $entry = Audit::action('network.access.revoked')
            ->on($grant)
            ->forOrganization($grant->organization_id)
            ->because($reason)
            ->withMetadata(['capability' => $grant->capability->value]);

        // An expiry has no actor, and saying so is the point: a record whose
        // author was invented would be a record that lied about who acted.
        $actor instanceof StaffUser
            ? $entry->by($actor)->write()
            : $entry->bySystem('automation.actor.scheduler')->write();

        return $grant;
    }

    /**
     * Whether this person currently holds this capability by grant.
     *
     * Asked of the **timestamps**, never of a state column and never of the
     * sweep having run: a scheduler that was down for three hours must not
     * leave an expired grant standing, and a gate that read a column would.
     *
     * Scoped to the node when the caller names one, and a grant with no node
     * covers every node — a grant for "Connect" generally is a normal thing
     * to give somebody for an afternoon.
     */
    public function allows(
        StaffUser $holder,
        GrantableCapability $capability,
        ?ResourceNode $node = null,
    ): bool {
        return AccessGrant::query()
            ->live()
            ->where('staff_user_id', $holder->id)
            ->where('capability', $capability->value)
            ->when(
                $node !== null,
                static fn ($query) => $query->where(static fn ($inner) => $inner
                    ->whereNull('resource_node_id')
                    ->orWhere('resource_node_id', $node?->id)),
            )
            ->exists();
    }
}
