<?php

declare(strict_types=1);

namespace App\Application\Api\Devices;

use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * The two kinds of person who can hold a device, and the one question asked
 * of both.
 *
 * `maySignIn()` is the property that makes issuing a token safe at all:
 * revoking somebody's access revokes their devices with it. It already exists
 * for a contact inside `AuthenticateApiToken` (`portal_access`); this is the
 * same rule stated once, for both guards, so the staff half cannot be written
 * differently by accident.
 *
 * A `match` on the class with no default, because a third kind of owner must
 * fail here rather than silently answer "yes, they may sign in".
 */
final readonly class DeviceOwners
{
    public function maySignIn(Model $owner): bool
    {
        return match (true) {
            $owner instanceof Contact => $owner->portal_access,
            $owner instanceof StaffUser => $owner->status === AccountStatus::Active,
            default => throw new RuntimeException('A device belongs to a contact or a staff user.'),
        };
    }

    /**
     * Mint an access token for whichever kind of person this is.
     *
     * Here rather than in `DeviceSessions` because this is the class that
     * knows there are exactly two kinds — and because `createToken()` comes
     * from a trait rather than an interface, so a parameter typed `Model`
     * cannot see it. A `match` narrowing to the two real classes is the
     * honest way to say so, and it fails on a third.
     *
     * @param  list<string>  $scopes
     */
    public function createToken(
        Model $owner,
        string $name,
        array $scopes,
        CarbonImmutable $expiresAt,
    ): NewAccessToken {
        return match (true) {
            $owner instanceof Contact,
            $owner instanceof StaffUser => $owner->createToken($name, $scopes, $expiresAt),
            default => throw new RuntimeException('A device belongs to a contact or a staff user.'),
        };
    }

    public function organizationOf(Model $owner): string
    {
        return match (true) {
            $owner instanceof Contact, $owner instanceof StaffUser => $owner->organization_id,
            default => throw new RuntimeException('A device belongs to a contact or a staff user.'),
        };
    }
}
